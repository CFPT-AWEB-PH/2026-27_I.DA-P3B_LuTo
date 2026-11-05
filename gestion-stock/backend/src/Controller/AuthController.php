<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Auth\LoginLinks;
use App\Auth\LoginService;
use App\Auth\RateLimiter;
use App\Auth\SessionManager;
use App\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    /** Modes de connexion disponibles, pour la page de connexion (public). */
    public static function config(Request $request): Response
    {
        return Response::json([
            'login_links' => LoginLinks::enabled(),
            'google' => Config::get('GOOGLE_CLIENT_ID') !== null && Config::get('GOOGLE_CLIENT_SECRET') !== null && $request->https,
        ]);
    }

    public static function me(Request $request): Response
    {
        return Response::json($request->user()->toArray());
    }

    /** Ouverture d'un lien à usage unique : navigation du navigateur, d'où les redirections. */
    public static function link(Request $request): Response
    {
        $bucket = 'login-failures:' . $request->ip;
        try {
            // 20 échecs par tranche de 10 minutes et par adresse ; les connexions réussies ne comptent pas
            RateLimiter::assertAllowed($bucket, 20, 600);
            $email = LoginLinks::consume((string) ($request->query['token'] ?? ''));
            $userId = LoginService::login($email, $request->ip);
        } catch (HttpException $e) {
            if ($e->status !== 429) {
                RateLimiter::hit($bucket, 600);
            }
            $code = $e->details['code'] ?? ($e->status === 429 ? 'rate_limited' : 'error');
            return Response::redirect('/login?error=' . rawurlencode((string) $code));
        }
        SessionManager::create($userId, $request);
        return Response::redirect('/');
    }

    /** Connexion par identifiant et mot de passe (fiche remise à la personne). */
    public static function login(Request $request): Response
    {
        $input = $request->input();
        $username = mb_strtolower($input->string('username', 60));
        $password = $input->string('password', 200);
        // 30 échecs par adresse (une classe entière partage souvent la même) et 10 par compte, sur 10 minutes
        $ipBucket = 'password-ip:' . $request->ip;
        $userBucket = 'password-user:' . hash('sha256', $username);
        RateLimiter::assertAllowed($ipBucket, 30, 600);
        RateLimiter::assertAllowed($userBucket, 10, 600);
        try {
            $userId = LoginService::loginWithPassword($username, $password, $request->ip);
        } catch (HttpException $e) {
            RateLimiter::hit($ipBucket, 600);
            RateLimiter::hit($userBucket, 600);
            throw $e;
        }
        SessionManager::create($userId, $request);
        return Response::json(['ok' => true]);
    }

    public static function logout(Request $request): Response
    {
        $auth = $request->user();
        SessionManager::destroy($auth, $request);
        Audit::log($auth->userId, 'auth.logout', 'user', $auth->userId, [], $request->ip);
        return Response::noContent();
    }
}
