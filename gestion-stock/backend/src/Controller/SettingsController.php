<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Database;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;

/** Paramètres de l'application (lecture : tout utilisateur connecté ; écriture : settings.manage). */
final class SettingsController
{
    public static function show(Request $request): Response
    {
        $request->user();
        return Response::json(['settings' => self::all()]);
    }

    public static function update(Request $request): Response
    {
        $auth = $request->requirePermission('settings.manage');
        $in = $request->input();
        $changes = [];
        if ($in->has('app.name')) {
            $changes['app.name'] = $in->string('app.name', 80);
        }
        if ($in->has('fill.colors')) {
            $colors = $request->json()['fill.colors'];
            if (!is_array($colors)) {
                Input::fail('fill.colors', 'Objet { empty, partial, full } attendu.');
            }
            $sub = new Input($colors);
            $changes['fill.colors'] = ['empty' => $sub->color('empty'), 'partial' => $sub->color('partial'), 'full' => $sub->color('full')];
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($changes as $key => $value) {
            $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
        }
        if ($changes !== []) {
            Audit::log($auth->userId, 'settings.updated', 'settings', null, $changes, $request->ip);
        }
        return Response::json(['settings' => self::all()]);
    }

    /** @return array<string, mixed> */
    private static function all(): array
    {
        $settings = [];
        foreach (Database::connection()->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $row) {
            $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
        }
        return $settings;
    }
}
