<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class CronExpression extends Constraint
{
    public string $message = 'The value "{{ value }}" is not a valid cron expression.';
}
