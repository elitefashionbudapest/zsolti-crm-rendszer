<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Auth\Auth;
use App\Documents\DocumentRepository;
use App\Documents\DocumentStorage;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Az ügyfél a saját, megosztott dokumentumait látja és töltheti le.
 */
final class DocumentsController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private DocumentRepository $documents,
        private DocumentStorage $storage,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $clientId = $this->auth->clientId();
        $documents = $clientId !== null ? $this->documents->forClientShared($clientId) : [];

        return $this->twig->render($response, 'portal/documents.twig', [
            'active' => 'documents',
            'documents' => $documents,
        ]);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $doc = $this->documents->find((int) $args['id']);
        if (
            $doc === null
            || (int) $doc['client_id'] !== $this->auth->clientId()
            || $doc['visibility'] !== 'shared'
        ) {
            return $response->withStatus(404);
        }

        $stream = (new StreamFactory())->createStreamFromFile($this->storage->fullPath((string) $doc['stored_path']));

        return $response
            ->withHeader('Content-Type', (string) $doc['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename((string) $doc['original_name']) . '"')
            ->withBody($stream);
    }
}
