<?php
/*
 * Convertit les anciennes légendes (dossier Legend/ avec un fichier HTML
 * par média et Legend.html pour l'introduction) en un fichier
 * legendes.txt par album.
 *
 * Les anciens fichiers ne sont pas supprimés. Un album qui a déjà un
 * legendes.txt n'est pas modifié, sauf avec --force.
 *
 * Usage : php bin/migrate-legends.php [--dry-run] [--force] [dossier...]
 * Sans dossier, toutes les galeries sont parcourues.
 */

if (PHP_SAPI !== 'cli')
{
        http_response_code(404);
        exit;
}

require_once __DIR__ . '/../lib/media.php';

function LegacyHtmlToText($Html)
{
        $Html = (string) $Html;

        if (($Html !== '') && (preg_match('//u', $Html) !== 1))
        {
                $Html = mb_convert_encoding($Html, 'UTF-8', 'ISO-8859-1');
        }

        $Html = preg_replace('/<br\s*\/?>/i', "\n", $Html);
        $Html = preg_replace('/<\/(p|div|li)>/i', "\n", $Html);
        $Text = html_entity_decode(strip_tags($Html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $Lines = array();

        foreach (preg_split('/\r\n|\r|\n/', $Text) as $Line)
        {
                $Line = trim(preg_replace('/[ \t]+/', ' ', $Line));

                if ($Line !== '')
                {
                        $Lines[] = $Line;
                }
        }

        return $Lines;
}

function BuildLegendFile($AlbumPath)
{
        $LegendDirectory = $AlbumPath . '/Legend';
        $Output = '';

        if (is_file($LegendDirectory . '/Legend.html'))
        {
                $IntroLines = LegacyHtmlToText(file_get_contents($LegendDirectory . '/Legend.html'));

                if (count($IntroLines) > 0)
                {
                        $Output .= implode("\n", $IntroLines) . "\n\n";
                }
        }

        $Entries = scandir($AlbumPath);
        natcasesort($Entries);

        foreach ($Entries as $Entry)
        {
                if (IsIgnoredMediaEntry($Entry) || (is_file($AlbumPath . '/' . $Entry) === FALSE))
                {
                        continue;
                }

                // L'ancien site nommait la légende d'après le nom avant le premier point
                $Prefix = explode('.', $Entry);
                $LegendPath = $LegendDirectory . '/' . $Prefix[0] . '.html';

                if (is_file($LegendPath) === FALSE)
                {
                        continue;
                }

                $Lines = LegacyHtmlToText(file_get_contents($LegendPath));

                if (count($Lines) === 0)
                {
                        continue;
                }

                $Output .= $Entry . ': ' . array_shift($Lines) . "\n";

                foreach ($Lines as $Line)
                {
                        $Output .= '  ' . $Line . "\n";
                }
        }

        return $Output;
}

function MigrateTree($Path, $DryRun, $Force, &$Count)
{
        if (is_dir($Path . '/Legend'))
        {
                $Target = $Path . '/' . MEDIA_LEGEND_FILE;
                $Content = BuildLegendFile($Path);

                if ($Content === '')
                {
                        echo 'Aucune légende : ' . $Path . PHP_EOL;
                }
                else if (is_file($Target) && ($Force === FALSE))
                {
                        echo 'Déjà migré (utiliser --force pour remplacer) : ' . $Target . PHP_EOL;
                }
                else if ($DryRun === TRUE)
                {
                        echo '--- ' . $Target . PHP_EOL . $Content;
                        $Count++;
                }
                else if (file_put_contents($Target, $Content) === FALSE)
                {
                        echo 'Écriture impossible : ' . $Target . PHP_EOL;
                }
                else
                {
                        echo 'Créé : ' . $Target . PHP_EOL;
                        $Count++;
                }
        }

        foreach (scandir($Path) as $Entry)
        {
                if ((IsIgnoredMediaEntry($Entry) === FALSE) && is_dir($Path . '/' . $Entry))
                {
                        MigrateTree($Path . '/' . $Entry, $DryRun, $Force, $Count);
                }
        }
}

$DryRun = FALSE;
$Force = FALSE;
$Directories = array();

foreach (array_slice($argv, 1) as $Argument)
{
        if ($Argument === '--dry-run')
        {
                $DryRun = TRUE;
        }
        else if ($Argument === '--force')
        {
                $Force = TRUE;
        }
        else
        {
                $Directories[] = rtrim($Argument, '/');
        }
}

if (count($Directories) === 0)
{
        foreach (GetGalleryRootNames() as $RootName)
        {
                $Directories[] = GetMediaBaseDirectory() . '/' . $RootName;
        }
}

$Count = 0;

foreach ($Directories as $Directory)
{
        if (is_dir($Directory))
        {
                MigrateTree($Directory, $DryRun, $Force, $Count);
        }
        else
        {
                echo 'Dossier introuvable : ' . $Directory . PHP_EOL;
        }
}

echo $Count . ' album(s) ' . ($DryRun ? 'à migrer.' : 'migré(s).') . PHP_EOL;
