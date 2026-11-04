<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Parser;
use Throwable;
use Thuraaung\Rulecraft\Exceptions\InvalidExpressionException;

class ExpressionEvaluator
{
    /** @var ExpressionLanguage */
    private $language;

    public function __construct(ExpressionLanguage $language)
    {
        $this->language = $language;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function evaluate(string $expression, array $data): bool
    {
        return (bool) $this->language->evaluate($expression, $data);
    }

    public function lint(string $expression): void
    {
        try {
            $flag = Parser::class.'::IGNORE_UNKNOWN_VARIABLES';

            if (defined($flag)) {
                $this->language->lint($expression, [], constant($flag));
            } else {
                $this->language->lint($expression, null);
            }
        } catch (Throwable $error) {
            throw new InvalidExpressionException($error->getMessage(), 0, $error);
        }
    }

    /**
     * @param  array<int, string>  $variables
     */
    public function validate(string $expression, array $variables = []): void
    {
        try {
            $this->language->parse($expression, $variables);
        } catch (Throwable $error) {
            throw new InvalidExpressionException($error->getMessage(), 0, $error);
        }
    }
}
