<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\PresetArchitect;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\CannotActivateException;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande CLI pour générer un preset LLM optimal.
 *
 * Usage :
 *   bin/console synapse:preset:suggest                                  # heuristique auto
 *   bin/console synapse:preset:suggest "Je veux un modèle rapide"       # LLM-assisté si possible
 *   bin/console synapse:preset:suggest --heuristic --activate           # heuristique + activation
 *   bin/console synapse:preset:suggest --provider=anthropic --dry-run   # filtre provider, sans créer
 */
#[AsCommand(
    name: 'synapse:preset:suggest',
    description: 'Recommande et crée un preset LLM optimal.',
)]
class PresetSuggestCommand extends Command
{
    public function __construct(
        private readonly PresetArchitect $generatorAgent,
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('description', InputArgument::OPTIONAL, 'Description du besoin en langage naturel')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche la recommandation sans créer le preset')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Active le preset créé comme preset par défaut')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Filtrer les modèles par provider (ex: anthropic, ovh)')
            ->addOption('heuristic', null, InputOption::VALUE_NONE, 'Forcer le mode heuristique (pas d\'appel LLM)')
            ->setHelp(<<<'HELP'
La commande <info>synapse:preset:suggest</info> analyse les providers configurés et recommande
un preset optimal pour le chat.

<comment>Modes :</comment>
  <info>Heuristique</info> — sélection déterministe (balanced > flagship > fast, puis par prix)
  <info>LLM-assisté</info> — utilise le LLM actif pour affiner la recommandation (si disponible)

<comment>Exemples :</comment>
  <info>bin/console synapse:preset:suggest</info>
  <info>bin/console synapse:preset:suggest "Un modèle économique pour du support client"</info>
  <info>bin/console synapse:preset:suggest --provider=ovh --heuristic</info>
  <info>bin/console synapse:preset:suggest --activate</info>
  <info>bin/console synapse:preset:suggest --dry-run</info>

Le preset est créé <comment>inactif</comment> par défaut. Utilisez <info>--activate</info> pour l'activer immédiatement.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synapse — Générateur de preset');

        $descriptionRaw = $input->getArgument('description');
        $description = is_string($descriptionRaw) ? $descriptionRaw : null;
        $providerFilter = $input->getOption('provider');
        $dryRun = (bool) $input->getOption('dry-run');
        $activate = (bool) $input->getOption('activate');
        $heuristicOnly = (bool) $input->getOption('heuristic');

        $io->text('Scan des providers et modèles disponibles…');

        try {
            $recommendation = $this->generatorAgent->generate(
                $description,
                is_string($providerFilter) ? $providerFilter : null,
                $heuristicOnly,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Afficher la recommandation
        $this->renderRecommendation($io, $recommendation);

        if ($dryRun) {
            $io->note('Mode dry-run : preset non créé.');

            return Command::SUCCESS;
        }

        // Créer le preset
        $preset = $recommendation->toPresetEntity();
        $this->em->persist($preset);
        $this->em->flush();

        $io->success(sprintf(
            'Preset « %s » créé (clé : %s, inactif).',
            $preset->getName(),
            $preset->getKey(),
        ));

        // Activer si demandé
        if ($activate) {
            try {
                $this->presetRepo->activate($preset);
                $io->success(sprintf('Preset « %s » activé comme preset par défaut.', $preset->getName()));
            } catch (CannotActivateException $e) {
                $io->error(sprintf('Impossible d\'activer le preset : %s', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private function renderRecommendation(SymfonyStyle $io, \ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\PresetRecommendation $recommendation): void
    {
        $io->section('Recommandation');

        $mode = $recommendation->llmAssisted ? 'LLM-assisté' : 'Heuristique';
        $io->text(sprintf('Mode : <info>%s</info>', $mode));

        $thinkingInfo = 'Désactivé';
        if (null !== $recommendation->providerOptions) {
            $thinking = $recommendation->providerOptions['thinking'] ?? null;
            if (is_array($thinking) && isset($thinking['budget_tokens'])) {
                $thinkingInfo = sprintf('Activé (budget : %d tokens)', $thinking['budget_tokens']);
            }
        }

        $io->table(
            ['Paramètre', 'Valeur'],
            [
                ['Provider', $recommendation->provider],
                ['Modèle', $recommendation->model],
                ['Gamme', $recommendation->range->label()],
                ['Nom suggéré', $recommendation->suggestedName],
                ['Clé', $recommendation->suggestedKey],
                ['Température', (string) $recommendation->temperature],
                ['Top P', (string) $recommendation->topP],
                ['Top K', null === $recommendation->topK ? '—' : (string) $recommendation->topK],
                ['Max Output Tokens', null === $recommendation->maxOutputTokens ? '(défaut modèle)' : (string) $recommendation->maxOutputTokens],
                ['Streaming', $recommendation->streamingEnabled ? 'Oui' : 'Non'],
                ['Thinking', $thinkingInfo],
                ['RGPD', $recommendation->rgpdRisk ?? 'Aucun risque intrinsèque'],
            ],
        );

        $io->section('Justification');
        $io->text($recommendation->justification);
    }
}
