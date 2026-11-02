<?php

declare(strict_types=1);

namespace App\Space;

/**
 * Géométrie 2D au sol (plan X-Z, en millimètres) et volumes intérieurs des rangements.
 * Les conventions sont identiques à celles de frontend/assets/js/spatial.js.
 */
final class Geometry
{
    /** Tolérance : deux éléments qui se touchent ne se chevauchent pas. */
    private const EPS = 0.5;

    /**
     * Coins de l'emprise au sol d'un meuble : coin arrière-gauche en (x, z), rotation autour de ce coin
     * (même sens que rotation.y de Three.js).
     *
     * @return list<array{float, float}>
     */
    public static function footprint(int $x, int $z, int $width, int $depth, int $rotationDeg): array
    {
        $a = deg2rad($rotationDeg);
        $cos = cos($a);
        $sin = sin($a);
        $corners = [];
        foreach ([[0, 0], [$width, 0], [$width, $depth], [0, $depth]] as [$lx, $lz]) {
            $corners[] = [$x + $lx * $cos + $lz * $sin, $z - $lx * $sin + $lz * $cos];
        }
        return $corners;
    }

    /** @param list<array{float|int, float|int}> $polygon */
    public static function area(array $polygon): float
    {
        $sum = 0.0;
        $n = count($polygon);
        for ($i = 0; $i < $n; $i++) {
            [$x1, $z1] = $polygon[$i];
            [$x2, $z2] = $polygon[($i + 1) % $n];
            $sum += $x1 * $z2 - $x2 * $z1;
        }
        return abs($sum) / 2;
    }

    /** Polygone sans auto-intersection (segments non adjacents disjoints). */
    public static function isSimple(array $polygon): bool
    {
        $n = count($polygon);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $n - 1)) {
                    continue;
                }
                if (self::segmentsTouch($polygon[$i], $polygon[($i + 1) % $n], $polygon[$j], $polygon[($j + 1) % $n])) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Point à l'intérieur ou sur le bord du polygone. */
    public static function containsPoint(array $polygon, float $x, float $z): bool
    {
        $n = count($polygon);
        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $zi] = $polygon[$i];
            [$xj, $zj] = $polygon[$j];
            if (self::distanceToSegment($x, $z, $xi, $zi, $xj, $zj) <= self::EPS) {
                return true;
            }
            if (($zi > $z) !== ($zj > $z) && $x < ($xj - $xi) * ($z - $zi) / ($zj - $zi) + $xi) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** Polygone convexe $inner entièrement contenu dans $outer (bords communs admis). */
    public static function containsPolygon(array $outer, array $inner): bool
    {
        foreach ($inner as [$x, $z]) {
            if (!self::containsPoint($outer, $x, $z)) {
                return false;
            }
        }
        $n = count($outer);
        $m = count($inner);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $m; $j++) {
                if (self::segmentsCross($outer[$i], $outer[($i + 1) % $n], $inner[$j], $inner[($j + 1) % $m])) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Chevauchement de deux polygones convexes (théorème de l'axe séparateur), contact admis. */
    public static function convexOverlap(array $a, array $b): bool
    {
        foreach ([$a, $b] as $polygon) {
            $n = count($polygon);
            for ($i = 0; $i < $n; $i++) {
                [$x1, $z1] = $polygon[$i];
                [$x2, $z2] = $polygon[($i + 1) % $n];
                $nx = $z1 - $z2;
                $nz = $x2 - $x1;
                $length = hypot($nx, $nz);
                if ($length == 0.0) {
                    continue;
                }
                [$minA, $maxA] = self::project($a, $nx / $length, $nz / $length);
                [$minB, $maxB] = self::project($b, $nx / $length, $nz / $length);
                if ($maxA <= $minB + self::EPS || $maxB <= $minA + self::EPS) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Intervalles [a1, a2] et [b1, b2] qui se recouvrent réellement. */
    public static function rangesOverlap(float $a1, float $a2, float $b1, float $b2): bool
    {
        return $a1 < $b2 - self::EPS && $b1 < $a2 - self::EPS;
    }

    /**
     * Volume intérieur utilisable d'un rangement, dans son repère local :
     * x de gauche à droite, y de bas en haut, z de l'arrière vers l'avant.
     *
     * @return array{width: int, depth: int, height: int, x: int, y: int, z: int}|null null si aucun volume
     */
    public static function interior(string $shape, int $width, int $depth, int $height, int $panel): ?array
    {
        $box = match ($shape) {
            // façade ouverte : deux côtés, dessus, dessous, fond
            'cabinet', 'compartment', 'cell' => [$width - 2 * $panel, $depth - $panel, $height - 2 * $panel, $panel, $panel, $panel],
            // dessus ouvert : quatre côtés et fond
            'drawer', 'box', 'bin' => [$width - 2 * $panel, $depth - 2 * $panel, $height - $panel, $panel, $panel, $panel],
            // planche en bas, espace utile au-dessus
            'shelf' => [$width, $depth, $height - $panel, 0, $panel, 0],
            default => null,
        };
        if ($box === null || min($box[0], $box[1], $box[2]) <= 0) {
            return null;
        }
        return ['width' => $box[0], 'depth' => $box[1], 'height' => $box[2], 'x' => $box[3], 'y' => $box[4], 'z' => $box[5]];
    }

    /** @return array{float, float} */
    private static function project(array $polygon, float $nx, float $nz): array
    {
        $min = INF;
        $max = -INF;
        foreach ($polygon as [$x, $z]) {
            $p = $x * $nx + $z * $nz;
            $min = min($min, $p);
            $max = max($max, $p);
        }
        return [$min, $max];
    }

    private static function orientation(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    /** Croisement franc (les segments se traversent, pas un simple contact). */
    private static function segmentsCross(array $a, array $b, array $c, array $d): bool
    {
        $o1 = self::orientation($a, $b, $c);
        $o2 = self::orientation($a, $b, $d);
        $o3 = self::orientation($c, $d, $a);
        $o4 = self::orientation($c, $d, $b);
        $tol = 1e-6;
        return (($o1 > $tol && $o2 < -$tol) || ($o1 < -$tol && $o2 > $tol))
            && (($o3 > $tol && $o4 < -$tol) || ($o3 < -$tol && $o4 > $tol));
    }

    /** Segments qui se touchent ou se croisent (utilisé pour la simplicité du contour). */
    private static function segmentsTouch(array $a, array $b, array $c, array $d): bool
    {
        if (self::segmentsCross($a, $b, $c, $d)) {
            return true;
        }
        return self::distanceToSegment($c[0], $c[1], $a[0], $a[1], $b[0], $b[1]) < self::EPS
            || self::distanceToSegment($d[0], $d[1], $a[0], $a[1], $b[0], $b[1]) < self::EPS
            || self::distanceToSegment($a[0], $a[1], $c[0], $c[1], $d[0], $d[1]) < self::EPS
            || self::distanceToSegment($b[0], $b[1], $c[0], $c[1], $d[0], $d[1]) < self::EPS;
    }

    private static function distanceToSegment(float $px, float $pz, float $x1, float $z1, float $x2, float $z2): float
    {
        $dx = $x2 - $x1;
        $dz = $z2 - $z1;
        $lengthSq = $dx * $dx + $dz * $dz;
        $t = $lengthSq == 0.0 ? 0.0 : max(0.0, min(1.0, (($px - $x1) * $dx + ($pz - $z1) * $dz) / $lengthSq));
        return hypot($px - ($x1 + $t * $dx), $pz - ($z1 + $t * $dz));
    }
}
