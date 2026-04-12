<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitect;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitectProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande CLI pour l'{@see AgentArchitect} — Phase 11.
 *
 * Usage :
 *   bin/console synapse:architect create-agent "Un agent de support technique pour les utilisateurs"
 *   bin/console synapse:architect improve-prompt support_technique "Rendre le prompt plus concis"
 *   bin/console synapse:architect create-workflow "Workflow d'analyse de document puis résumé"
 *   bin/console synapse:architect create-agent "..." --dry-run   # affiche la proposition sans l'appliquer
 */
#[AsCommand(
    name: 'synapse:architect',
    description: 'Génère une définition d\'agent ou de workflow via l\'agent architecte (Phase 11).',
)]
class ArchitectCommand extends Command
{
    public function __construct(
        private readonly AgentArchitect $architectAgent,
        private readonly AgentArchitectProcessor $proposalProcessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action : create-agent, improve-prompt, create-workflow')
            ->addArgument('description', InputArgument::REQUIRED, 'Description en langage naturel')
            ->addOption('agent-key', null, InputOption::VALUE_REQUIRED, 'Clé de l\'agent cible (requis pour improve-prompt)')
            ->addOption('instructions', null, InputOption::VALUE_REQUIRED, 'Directives spécifiques supplémentaires')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche la proposition sans l\'appliquer')
            ->setHelp(<<<'HELP'
La commande <info>synapse:architect</info> utilise un LLM pour générer des définitions structurées.

<comment>Actions disponibles :</comment>
  <info>create-agent</info>      Crée un nouvel agent (inactif, prompt en pending)
  <info>improve-prompt</info>    Propose un nouveau prompt pour un agent existant
  <info>create-workflow</info>   Crée un nouveau workflow (inactif)

<comment>Exemples :</comment>
  <info>bin/console synapse:architect create-agent "Un agent de support technique"</info>
  <info>bin/console synapse:architect improve-prompt "Rendre plus concis" --agent-key=support_technique</info>
  <info>bin/console synapse:architect create-workflow "Analyser un document puis le résumer"</info>
  <info>bin/console synapse:architect create-agent "..." --dry-run</info>

Prérequis : configurer <comment>synapse.governance.architect_preset_key</comment> avec un preset supportant les structured outputs.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $actionRaw = $input->getArgument('action');
        $action = is_string($actionRaw) ? $actionRaw : '';

        // Normaliser les tirets vers underscores (CLI: create-agent → create_agent)
        $action = str_replace('-', '_', $action);

        $descriptionRaw = $input->getArgument('description');
        $description = is_string($descriptionRaw) ? $descriptionRaw : '';

        $agentKey = $input->getOption('agent-key');
        $instructions = $input->getOption('instructions');
        $dryRun = (bool) $input->getOption('dry-run');

        if ('improve_prompt' === $action && (!is_string($agentKey) || '' === $agentKey)) {
            $io->error('L\'option --agent-key est requise pour l\'action improve-prompt.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Architecte — %s', $action));
        $io->text('Appel du LLM en cours…');

        // Construire l'input structuré
        $structured = [
            'action' => $action,
            'description' => $description,
        ];
        if (is_string($agentKey) && '' !== $agentKey) {
            $structured['agent_key'] = $agentKey;
        }
        if (is_string($instructions) && '' !== $instructions) {
            $structured['instructions'] = $instructions;
        }

        $agentOutput = $this->architectAgent->call(Input::ofStructured($structured));
        $data = $agentOutput->getData();

        // Erreur de l'agent
        if (isset($data['error']) && is_string($data['error'])) {
            $io->error($data['error']);

            return Command::FAILURE;
        }

        $this->renderProposal($io, $data, $action);

        // Usage tokens
        $usage = $agentOutput->getUsage();
        if ([] !== $usage) {
            $io->section('Consommation');
            $io->table(
                ['Métrique', 'Valeur'],
                array_map(
                    fn ($k, $v) => [(string) $k, (string) $v],
                    array_keys($usage),
                    $usage,
                ),
            );
        }

        if ($dryRun) {
            $io->note('Mode dry-run : proposition non appliquée.');

            return Command::SUCCESS;
        }

        // Appliquer la proposition
        $io->section('Application');

        // Pour improve_prompt, injecter la clé d'agent dans la proposition
        if ('improve_prompt' === $action && is_string($agentKey)) {
            $data['_agent_key'] = $agentKey;
        }

        try {
            $result = $this->proposalProcessor->process($data);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($result['message']);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderProposal(SymfonyStyle $io, array $data, string $action): void
    {
        $io->section('Proposition générée');

        match ($action) {
            'create_agent' => $this->renderAgentProposal($io, $data),
            'improve_prompt' => $this->renderPromptProposal($io, $data),
            'create_workflow' => $this->renderWorkflowProposal($io, $data),
            default => $io->text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
        };

        $reasoning = $data['reasoning'] ?? null;
        if (is_string($reasoning) && '' !== $reasoning) {
            $io->section('Raisonnement');
            $io->text($reasoning);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderAgentProposal(SymfonyStyle $io, array $data): void
    {
        $io->definitionList(
            ['Clé' => (string) ($data['key'] ?? '?')],
            ['Nom' => (string) ($data['name'] ?? '?')],
            ['Emoji' => (string) ($data['emoji'] ?? '?')],
            ['Description' => (string) ($data['description'] ?? '?')],
        );

        $prompt = $data['system_prompt'] ?? null;
        if (is_string($prompt)) {
            $io->section('System Prompt');
            $io->text($prompt);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPromptProposal(SymfonyStyle $io, array $data): void
    {
        $summary = $data['changes_summary'] ?? null;
        if (is_string($summary)) {
            $io->section('Résumé des changements');
            $io->text($summary);
        }

        $prompt = $data['new_system_prompt'] ?? null;
        if (is_string($prompt)) {
            $io->section('Nouveau System Prompt');
            $io->text($prompt);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderWorkflowProposal(SymfonyStyle $io, array $data): void
    {
        $io->definitionList(
            ['Clé' => (string) ($data['key'] ?? '?')],
            ['Nom' => (string) ($data['name'] ?? '?')],
            ['Description' => (string) ($data['description'] ?? '?')],
        );

        $steps = $data['steps'] ?? null;
        if (is_array($steps)) {
            $io->section('Étapes');
            $rows = [];
            foreach ($steps as $i => $step) {
                if (!is_array($step)) {
                    continue;
                }
                $rows[] = [
                    (string) ($i + 1),
                    (string) ($step['name'] ?? '?'),
                    (string) ($step['agent_name'] ?? '?'),
                ];
            }
            $io->table(['#', 'Étape', 'Agent'], $rows);
        }
    }
}
