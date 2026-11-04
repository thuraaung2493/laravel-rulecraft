<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Builder\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Thuraaung\Rulecraft\Builder\RuleGroupBuilder;
use Thuraaung\Rulecraft\Exceptions\InvalidPolicyException;
use Thuraaung\Rulecraft\Exceptions\RuleGroupNotFoundException;
use Thuraaung\Rulecraft\Exceptions\StalePolicyException;
use Thuraaung\Rulecraft\Results\EvaluationResult;

class BuilderController extends Controller
{
    public function load(Request $request, RuleGroupBuilder $builder): JsonResponse
    {
        $name = $request->query('group');
        abort_unless(is_string($name) && $name !== '', 422, 'A group name is required.');

        try {
            return $this->json($builder->load($name));
        } catch (RuleGroupNotFoundException $error) {
            return $this->json(['message' => $error->getMessage()], 404);
        } catch (InvalidPolicyException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }
    }

    public function validateDraft(Request $request, RuleGroupBuilder $builder): JsonResponse
    {
        return $this->operate($request, $builder, 'validate');
    }

    public function preview(Request $request, RuleGroupBuilder $builder): JsonResponse
    {
        return $this->operate($request, $builder, 'preview');
    }

    public function save(Request $request, RuleGroupBuilder $builder): JsonResponse
    {
        return $this->operate($request, $builder, 'save');
    }

    public function asset(string $file): BinaryFileResponse
    {
        abort_unless(in_array($file, ['builder.js', 'builder.css', 'bootstrap.css'], true), 404);
        $type = $file === 'builder.js' ? 'application/javascript' : 'text/css';

        return response()->file(__DIR__.'/../../../resources/builder/'.$file, [
            'Content-Type' => $type.'; charset=UTF-8',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function operate(
        Request $request,
        RuleGroupBuilder $builder,
        string $mode
    ): JsonResponse {
        try {
            $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            if (! $body instanceof stdClass || ! ($body->group ?? null) instanceof stdClass) {
                throw new InvalidPolicyException('A group JSON object is required.');
            }
            if ($mode === 'validate') {
                return $this->json(['group' => $builder->validate($body->group)]);
            }
            if ($mode === 'preview') {
                $input = $body->context ?? null;
                if (property_exists($body, 'context_json')) {
                    if (! is_string($body->context_json)) {
                        throw new InvalidPolicyException('Context JSON must be a string.');
                    }
                    $input = json_decode($body->context_json, false, 512, JSON_THROW_ON_ERROR);
                }
                if (! $input instanceof stdClass) {
                    throw new InvalidPolicyException('Preview context must be a JSON object.');
                }
                $contextJson = json_encode(
                    $input, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                );
                $context = json_decode($contextJson, true, 512, JSON_THROW_ON_ERROR);

                return $this->json(['result' => $this->result(
                    $builder->preview($body->group, $context)
                )]);
            }
            if (isset($body->revision) && ! is_string($body->revision)) {
                throw new InvalidPolicyException('Revision must be a string or null.');
            }
            $group = $builder->save($body->group, $body->revision ?? null);

            return $this->json($builder->load($group->name));
        } catch (StalePolicyException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        } catch (InvalidPolicyException|JsonException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }
    }

    /** @return array<string, mixed> */
    private function result(EvaluationResult $result): array
    {
        $rules = [];
        foreach ($result->rules() as $rule) {
            $rules[] = ['name' => $rule->rule()->name, 'passed' => $rule->passed(),
                'message' => $rule->message(),
                'disabled' => $rule->disabled(), 'error' => $rule->error() === null ? null
                    : $rule->error()->getMessage()];
        }
        $children = [];
        foreach ($result->groups() as $child) {
            $children[] = $this->result($child);
        }

        return ['name' => $result->group()->name ?? '', 'passed' => $result->passed(),
            'failed_message' => $result->failedMessage(),
            'passed_message' => $result->passedMessage(),
            'disabled' => $result->disabled(), 'has_errors' => $result->hasErrors(),
            'validation_errors' => $result->validationErrors(),
            'rules' => $rules, 'groups' => $children];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
