<?php

/**
 * 1. Relative path: bridge/domain/error.php
 * 2. Description: The domain's own refusal — an exception carrying the HTTP
 *                 status and the message the caller should see. The domain
 *                 THROWS it; the transport (the front controllers) catches it
 *                 and turns it into the JSON refusal. Before this class the
 *                 domain called Router\refuse() directly, which ended the
 *                 request from inside the business logic — untestable outside
 *                 a web server, and impossible to reuse from a CLI.
 * 3. Expects: Nothing.
 * 4. Provides: Domain\Error — the one way the domain says "no".
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Domain;

/**
 * A refusal the domain speaks: the status the transport should answer and the
 * message the user should read. NOT an HTTP concern — the status is a number
 * the transport maps, never a response the domain writes itself.
 */
final class Error extends \RuntimeException
{
    /**
     * @param array<string, string> $headers extra response headers the
     *        transport must apply (e.g. X-Current-Hash on a 409 conflict) —
     *        FACTS the domain carries, applied by the transport, never set
     *        by the domain itself.
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }
}
