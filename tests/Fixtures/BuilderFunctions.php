<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Tests\Fixtures;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Thuraaung\Rulecraft\Contracts\BuilderExpressionProvider;

class BuilderFunctions implements BuilderExpressionProvider
{
    /** @var int */
    public static $calls = 0;

    /** @return list<ExpressionFunction> */
    public function getFunctions(): array
    {
        return [
            new ExpressionFunction('credit_score', function ($customer) {
                return '$variables["scores"][ '.$customer.' ]';
            }, function (array $data, $customer) {
                self::$calls++;

                return $data['scores'][$customer];
            }),
            new ExpressionFunction('eligible', function ($score) {
                return '('.$score.' >= 650)';
            }, function (array $data, $score) {
                self::$calls++;

                return $score >= 650;
            }),
            new ExpressionFunction('adjust_score', function ($score, $bonus) {
                return '('.$score.' + '.$bonus.')';
            }, function (array $data, $score, $bonus) {
                self::$calls++;

                return $score + $bonus;
            }),
            new ExpressionFunction('current_limit', function () {
                return '$variables["limit"]';
            }, function (array $data) {
                self::$calls++;

                return $data['limit'];
            }),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function getBuilderFunctions(): array
    {
        return [
            'credit_score' => ['label' => 'Credit score', 'return_type' => 'number',
                'arguments' => ['customer_id' => ['label' => 'Customer ID', 'type' => 'integer']]],
            'eligible' => ['label' => 'Eligible', 'return_type' => 'boolean',
                'arguments' => ['score' => ['label' => 'Score', 'type' => 'integer']]],
            'adjust_score' => ['label' => 'Adjusted score', 'return_type' => 'number',
                'arguments' => ['score' => ['label' => 'Score', 'type' => 'integer'],
                    'bonus' => ['label' => 'Bonus', 'type' => 'number']]],
            'current_limit' => ['label' => 'Current limit', 'return_type' => 'number',
                'arguments' => []],
        ];
    }
}
