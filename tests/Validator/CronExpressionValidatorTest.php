<?php

namespace App\Tests\Validator;

use App\Validator\CronExpression;
use App\Validator\CronExpressionValidator;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class CronExpressionValidatorTest extends MockeryTestCase
{
    private CronExpressionValidator $validator;
    private ExecutionContextInterface|\Mockery\MockInterface $context;

    protected function setUp(): void
    {
        $this->validator = new CronExpressionValidator();
        $this->context = \Mockery::mock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testNullValueIsValid(): void
    {
        $this->context->shouldNotReceive('buildViolation');

        $this->validator->validate(null, new CronExpression());
        $this->addToAssertionCount(1);
    }

    public function testEmptyStringIsValid(): void
    {
        $this->context->shouldNotReceive('buildViolation');

        $this->validator->validate('', new CronExpression());
        $this->addToAssertionCount(1);
    }

    public function testValidCronExpressionPasses(): void
    {
        $this->context->shouldNotReceive('buildViolation');

        $this->validator->validate('0 2 * * *', new CronExpression());
        $this->addToAssertionCount(1);
    }

    public function testValidCronEveryFiveMinutesPasses(): void
    {
        $this->context->shouldNotReceive('buildViolation');

        $this->validator->validate('*/5 * * * *', new CronExpression());
        $this->addToAssertionCount(1);
    }

    public function testValidCronComplexExpressionPasses(): void
    {
        $this->context->shouldNotReceive('buildViolation');

        $this->validator->validate('0 0 1,15 * *', new CronExpression());
        $this->addToAssertionCount(1);
    }

    public function testInvalidCronExpressionAddsViolation(): void
    {
        $constraint = new CronExpression();

        $violationBuilder = \Mockery::mock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->shouldReceive('setParameter')
            ->with('{{ value }}', 'not-a-cron')
            ->once()
            ->andReturnSelf();
        $violationBuilder->shouldReceive('addViolation')
            ->once();

        $this->context->shouldReceive('buildViolation')
            ->with($constraint->message)
            ->once()
            ->andReturn($violationBuilder);

        $this->validator->validate('not-a-cron', $constraint);
    }

    public function testInvalidCronTooFewFieldsAddsViolation(): void
    {
        $constraint = new CronExpression();

        $violationBuilder = \Mockery::mock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->shouldReceive('setParameter')
            ->with('{{ value }}', '* *')
            ->once()
            ->andReturnSelf();
        $violationBuilder->shouldReceive('addViolation')
            ->once();

        $this->context->shouldReceive('buildViolation')
            ->with($constraint->message)
            ->once()
            ->andReturn($violationBuilder);

        $this->validator->validate('* *', $constraint);
    }

    public function testInvalidCronOutOfRangeAddsViolation(): void
    {
        $constraint = new CronExpression();

        $violationBuilder = \Mockery::mock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->shouldReceive('setParameter')
            ->with('{{ value }}', '61 * * * *')
            ->once()
            ->andReturnSelf();
        $violationBuilder->shouldReceive('addViolation')
            ->once();

        $this->context->shouldReceive('buildViolation')
            ->with($constraint->message)
            ->once()
            ->andReturn($violationBuilder);

        $this->validator->validate('61 * * * *', $constraint);
    }
}
