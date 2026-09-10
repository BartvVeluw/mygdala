<?php

declare(strict_types=1);

namespace App\Service\Redirects;

use App\Repository\RedirectRepository;

/**
 * The read side: given the path a visitor asked for, where — if anywhere —
 * should they be sent?
 *
 * It answers only for a request that was ABOUT TO BECOME A 404. That ordering
 * is the whole safety property of this feature and it is enforced by where the
 * lookup is called from (RedirectGate), not by anything in this class: a
 * redirect can never shadow a page, a product, a collection, an application
 * route or a file on disk, because by the time these rows are consulted the
 * application has already decided it has nothing to serve.
 *
 * MATCHING is exact and on the path alone. No patterns, no prefixes, no
 * hostnames. The path is normalized by RedirectPath, so the visitor's
 * /oude-pagina/ finds the row stored as /oude-pagina.
 *
 * QUERY STRINGS. The query a visitor arrived with is never part of the match
 * (?utm_source=... must not stop a redirect from firing), and it is carried
 * over to an internal destination that does not define one of its own —
 * campaign parameters survive the move, which is the behaviour anybody
 * analysing traffic expects. A destination that DOES carry its own query
 * string wins outright: the editor wrote those parameters deliberately, and
 * gluing two query strings together produces a URL neither of them meant.
 *
 * CHAINS are followed, up to Redirect::MAX_CHAIN_DEPTH hops, so a page renamed
 * twice keeps its oldest URL working even in the moment before the write side
 * has collapsed the chain. Every path already visited is remembered, so a loop
 * that somehow reached the database — a row edited straight in SQL, say — ends
 * as "no redirect" and a log line, never as a request that never returns.
 *
 * FAILING SAFE. Every lookup is wrapped: a database that is unreachable makes
 * this return null, which means the visitor gets the 404 they were already
 * going to get. A redirect is an improvement on a 404, never a prerequisite
 * for answering at all.
 */
final class RedirectResolver
{
    private RedirectRepository $repository;

    public function __construct(?RedirectRepository $repository = null)
    {
        $this->repository = $repository ?? new RedirectRepository();
    }

    /**
     * Where to send a request, or null to let it 404 as it would have.
     *
     * @return array{location: string, status: int}|null
     */
    public function resolveRequestUri(string $requestUri): ?array
    {
        $path = RedirectPath::fromRequestUri($requestUri);

        if ($path === null) {
            return null;
        }

        return $this->resolve($path, RedirectPath::queryFromRequestUri($requestUri));
    }

    /**
     * @param string $path  an already-normalized request path
     * @param string $query the raw query string the request arrived with, '' when none
     *
     * @return array{location: string, status: int}|null
     */
    public function resolve(string $path, string $query = ''): ?array
    {
        try {
            $row = $this->repository->findActiveBySourcePath($path);
        } catch (\Throwable $e) {
            error_log('[RedirectResolver] lookup failed for "' . $path . '": ' . $e->getMessage());

            return null;
        }

        if ($row === null) {
            return null;
        }

        // The status code comes from the FIRST row in the chain: it is the
        // answer to "what happened to the URL the visitor typed", and that is
        // what a temporary redirect is a statement about.
        $status = (int) $row['status_code'];
        if (!Redirect::isValidStatusCode($status)) {
            error_log('[RedirectResolver] refusing redirect ' . $path . ': stored status ' . $status . ' is not one of this application\'s');

            return null;
        }

        $final = $this->follow($row, $path);

        if ($final === null) {
            return null;
        }

        [$targetType, $targetValue] = $final;

        $disabledModule = RedirectTarget::disabledModuleFor($targetType, $targetValue);
        if ($disabledModule !== null) {
            // The destination belongs to a module that is switched off, so it
            // answers 404 itself. Sending the visitor there would swap one
            // dead URL for another and throw away the one they actually asked
            // for. The row stays exactly as stored — see MODULES.md, switching
            // a module off is not an uninstall — and starts working again the
            // moment the module does.
            error_log(sprintf(
                '[RedirectResolver] not redirecting %s: target %s belongs to the disabled module "%s"',
                $path,
                $targetValue,
                $disabledModule
            ));

            return null;
        }

        return [
            'location' => RedirectTarget::absoluteUrl($targetType, $this->withQuery($targetType, $targetValue, $query)),
            'status' => $status,
        ];
    }

    /**
     * Walks an internal chain to its end.
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: string}|null target type and value, null when the chain is unusable
     */
    private function follow(array $row, string $sourcePath): ?array
    {
        $seen = [$sourcePath => true];

        for ($hop = 0; $hop < Redirect::MAX_CHAIN_DEPTH; $hop++) {
            $type = (string) $row['target_type'];
            $value = (string) $row['target_value'];

            if (!RedirectTarget::isValidType($type)) {
                error_log('[RedirectResolver] refusing redirect ' . $sourcePath . ': stored target type "' . $type . '" is not one of this application\'s');

                return null;
            }

            if ($type !== RedirectTarget::TYPE_INTERNAL) {
                return [$type, $value];
            }

            $nextPath = RedirectPath::normalize(explode('?', $value, 2)[0]);

            if ($nextPath === null || isset($seen[$nextPath])) {
                if ($nextPath !== null) {
                    error_log('[RedirectResolver] refusing redirect ' . $sourcePath . ': the chain loops back to ' . $nextPath);
                }

                return null;
            }

            try {
                $next = $this->repository->findActiveBySourcePath($nextPath);
            } catch (\Throwable $e) {
                error_log('[RedirectResolver] chain lookup failed for "' . $nextPath . '": ' . $e->getMessage());

                return null;
            }

            if ($next === null) {
                // The end of the chain: this destination is not itself a
                // redirect source, so it is where the visitor goes.
                return [$type, $value];
            }

            $seen[$nextPath] = true;
            $row = $next;
        }

        error_log('[RedirectResolver] refusing redirect ' . $sourcePath . ': chain longer than ' . Redirect::MAX_CHAIN_DEPTH . ' steps');

        return null;
    }

    /**
     * Carries the incoming query string over to an internal destination that
     * has none of its own. An external destination is left completely alone:
     * this site does not get to decide what parameters another site receives.
     */
    private function withQuery(string $targetType, string $targetValue, string $query): string
    {
        if ($query === '' || $targetType !== RedirectTarget::TYPE_INTERNAL) {
            return $targetValue;
        }

        if (str_contains($targetValue, '?')) {
            return $targetValue;
        }

        return $targetValue . '?' . $query;
    }
}
