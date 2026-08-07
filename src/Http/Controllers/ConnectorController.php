<?php

declare(strict_types=1);

namespace Ruklab\Connector\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ruklab\Connector\Content\ContentRegistry;
use Ruklab\Connector\Content\ContentService;
use Ruklab\Connector\Content\MediaService;
use Ruklab\Connector\Content\MenuService;
use Ruklab\Connector\Support\ConnectorException;

/**
 * The surface this package adds, under `/ruklab/v1`.
 *
 * The same paths and the same shapes as the WordPress connector, so that
 * ruklab.app talks to a custom site and to a client's WordPress through one
 * driver and one set of tools. A site is a parameter there; the platform it
 * runs on should not become another one.
 */
final class ConnectorController
{
    public function __construct(
        private readonly ContentService $content = new ContentService,
        private readonly ContentRegistry $registry = new ContentRegistry,
        private readonly MenuService $menus = new MenuService,
        private readonly MediaService $media = new MediaService,
    ) {}

    /**
     * What this site is and what it offers, which is what the capability
     * probe stores so nothing is attempted blind.
     */
    public function info(): JsonResponse
    {
        return response()->json([
            'connector' => 'ruklab/connector',
            'version' => \Ruklab\Connector\ConnectorServiceProvider::VERSION,
            'platform' => 'laravel',
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'app_name' => config('app.name'),
            'site_url' => config('app.url'),
            'writes_enabled' => (bool) config('ruklab.writes_enabled', false),
            'types' => $this->registry->describe(),
            'menus' => $this->menus->available(),
        ]);
    }

    public function list(Request $request, string $type): JsonResponse
    {
        return $this->answer(fn (): array => $this->content->list($type, [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 20),
            'page' => $request->query('page', 1),
        ]));
    }

    public function show(string $type, string $id): JsonResponse
    {
        return $this->answer(fn (): array => $this->content->get($type, $id));
    }

    public function store(Request $request, string $type): JsonResponse
    {
        return $this->answer(fn (): array => $this->content->create($type, $request->all()), 201);
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        return $this->answer(fn (): array => $this->content->update($type, $id, $request->all()));
    }

    public function storeMedia(Request $request, string $type, string $id): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof \Illuminate\Http\UploadedFile) {
            return response()->json([
                'message' => 'No ha llegado ningún archivo. Se envía como multipart en el campo «file».',
            ], 422);
        }

        return $this->answer(fn (): array => $this->media->attach(
            $type,
            $id,
            (string) ($request->input('name') ?: 'featured'),
            $file,
        ));
    }

    public function snapshots(string $type, string $id): JsonResponse
    {
        return $this->answer(fn (): array => [
            'type' => $type,
            'id' => $id,
            'snapshots' => $this->content->snapshots($type, $id),
        ]);
    }

    public function menus(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => [
            'items' => $this->menus->tree($request->query('location')),
        ]);
    }

    public function storeMenuItem(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->menus->create($request->all()), 201);
    }

    public function updateMenuItem(Request $request, string $id): JsonResponse
    {
        return $this->answer(fn (): array => $this->menus->update($id, $request->all()));
    }

    public function reorderMenu(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->menus->reorder(
            (array) $request->input('item_ids', []),
            $request->input('location'),
        ));
    }

    public function rollback(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->content->rollback((int) $request->input('snapshot_id')));
    }

    /**
     * One place to turn a refusal into a status and a sentence, so no route
     * has to remember to do it.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function answer(callable $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json($work(), $status);
        } catch (ConnectorException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
