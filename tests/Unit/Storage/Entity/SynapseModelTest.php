<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModel;
use PHPUnit\Framework\TestCase;

class SynapseModelTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $model = new SynapseModel();

        $this->assertNull($model->getId());
        $this->assertSame('', $model->getProviderName());
        $this->assertSame('', $model->getModelId());
        $this->assertSame('', $model->getLabel());
        $this->assertTrue($model->isEnabled());
        $this->assertNull($model->getPricingInput());
        $this->assertNull($model->getPricingOutput());
        $this->assertNull($model->getPricingOutputImage());
        $this->assertSame('USD', $model->getCurrency());
        $this->assertSame(0, $model->getSortOrder());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $model = new SynapseModel();

        $model->setProviderName('openai')
            ->setModelId('gpt-4o')
            ->setLabel('GPT-4o')
            ->setIsEnabled(false)
            ->setPricingInput(2.5)
            ->setPricingOutput(10.0)
            ->setPricingOutputImage(25.0)
            ->setCurrency('EUR')
            ->setSortOrder(3);

        $this->assertSame('openai', $model->getProviderName());
        $this->assertSame('gpt-4o', $model->getModelId());
        $this->assertSame('GPT-4o', $model->getLabel());
        $this->assertFalse($model->isEnabled());
        $this->assertSame(2.5, $model->getPricingInput());
        $this->assertSame(10.0, $model->getPricingOutput());
        $this->assertSame(25.0, $model->getPricingOutputImage());
        $this->assertSame('EUR', $model->getCurrency());
        $this->assertSame(3, $model->getSortOrder());
    }

    public function testNullablePricingFields(): void
    {
        $model = new SynapseModel();
        $model->setPricingInput(5.0)->setPricingOutput(15.0)->setPricingOutputImage(30.0);

        $model->setPricingInput(null)->setPricingOutput(null)->setPricingOutputImage(null);

        $this->assertNull($model->getPricingInput());
        $this->assertNull($model->getPricingOutput());
        $this->assertNull($model->getPricingOutputImage());
    }
}
