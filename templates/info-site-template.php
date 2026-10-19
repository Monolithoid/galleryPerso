<?php
declare(strict_types=1);

/** @var string $phpConfigurationHtml */
/** @var array $gdInformation */
/** @var string $hardwareDetails */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Diagnostics serveur</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            margin: 2rem;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Nunito Sans', sans-serif;
        }

        h1 {
            margin-bottom: 1.5rem;
        }

        section {
            margin-bottom: 2rem;
        }

        dl {
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: 0.5rem 1rem;
        }

        dt {
            font-weight: bold;
        }

        dd {
            margin: 0;
        }

        .hardware-details {
            border: 1px solid #ccc;
            padding: 1rem;
            border-radius: 0.5rem;
        }
    </style>
</head>
<body>
    <h1>Diagnostics serveur</h1>
    <section>
        <h2>Configuration PHP</h2>
        <div>
            <?php echo $phpConfigurationHtml; ?>
        </div>
    </section>
    <?php
    if (count($gdInformation) > 0) {
        echo '<section>';
        echo '<h2>Informations GD</h2>';
        echo '<dl>';
        foreach ($gdInformation as $label => $value) {
            $key = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
            $displayValue = formatGdValue($value);
            echo '<dt>' . $key . '</dt>';
            echo '<dd>' . $displayValue . '</dd>';
        }
        echo '</dl>';
        echo '</section>';
    }
    ?>
    <section>
        <h2>Configuration machine serveur</h2>
        <?php
        if ($hardwareDetails !== '') {
            echo '<div class="hardware-details">';
            echo $hardwareDetails;
            echo '</div>';
        } else {
            echo '<p>Aucune information matérielle n\'est disponible.</p>';
        }
        ?>
    </section>
</body>
</html>
<?php
function formatGdValue($value): string
{
    if (is_bool($value)) {
        if ($value === true) {
            return 'Activé';
        }

        return 'Désactivé';
    }

    if (is_scalar($value)) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    return htmlspecialchars(json_encode($value), ENT_QUOTES, 'UTF-8');
}
