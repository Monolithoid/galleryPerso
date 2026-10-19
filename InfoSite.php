<?php
declare(strict_types=1);

const SITEIMAGEPERSO_DIAGNOSTICS_FLAG = 'SITEIMAGEPERSO_ENABLE_ADMIN_DIAGNOSTICS';
const SITEIMAGEPERSO_DIAGNOSTICS_USER = 'SITEIMAGEPERSO_ADMIN_DIAGNOSTICS_USER';
const SITEIMAGEPERSO_DIAGNOSTICS_PASSWORD = 'SITEIMAGEPERSO_ADMIN_DIAGNOSTICS_PASSWORD';
const SITEIMAGEPERSO_HARDWARE_FILE = __DIR__ . '/matos.html';

main();

function main(): void
{
    if (isDiagnosticsEnabled() === false) {
        respondNotFound();
    }

    requireAdminAuthentication();
    sendNoCacheHeaders();

    $phpConfigurationHtml = capturePhpConfiguration();
    $gdInformation = collectGdInformation();
    $hardwareDetails = loadHardwareDetails();

    require __DIR__ . '/templates/info-site-template.php';
}

function isDiagnosticsEnabled(): bool
{
    return readEnvironmentFlag(SITEIMAGEPERSO_DIAGNOSTICS_FLAG);
}

function readEnvironmentFlag(string $environmentName): bool
{
    $environmentValue = getenv($environmentName);

    if ($environmentValue === false) {
        return false;
    }

    $normalizedValue = strtolower(trim($environmentValue));
    $truthyValues = array('1', 'true', 'yes', 'on');
    $currentIndex = 0;
    $truthyCount = count($truthyValues);

    while ($currentIndex < $truthyCount) {
        if ($normalizedValue === $truthyValues[$currentIndex]) {
            return true;
        }

        $currentIndex = $currentIndex + 1;
    }

    return false;
}

function respondNotFound(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Page not found.';
    exit;
}

function requireAdminAuthentication(): void
{
    $username = getenv(SITEIMAGEPERSO_DIAGNOSTICS_USER);
    $password = getenv(SITEIMAGEPERSO_DIAGNOSTICS_PASSWORD);

    if ($username === false || $password === false) {
        respondNotFound();
    }

    $trimmedUsername = trim($username);
    $trimmedPassword = trim($password);

    if ($trimmedUsername === '' || $trimmedPassword === '') {
        respondNotFound();
    }

    if (isset($_SERVER['PHP_AUTH_USER']) === false || isset($_SERVER['PHP_AUTH_PW']) === false) {
        header('WWW-Authenticate: Basic realm="Site diagnostics"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Authentication required.';
        exit;
    }

    $suppliedUsername = (string) $_SERVER['PHP_AUTH_USER'];
    $suppliedPassword = (string) $_SERVER['PHP_AUTH_PW'];

    $validUsername = valuesMatch($trimmedUsername, $suppliedUsername);
    $validPassword = valuesMatch($trimmedPassword, $suppliedPassword);

    if ($validUsername === false || $validPassword === false) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Access denied.';
        exit;
    }
}

function valuesMatch(string $expectedValue, string $actualValue): bool
{
    if (function_exists('hash_equals')) {
        return hash_equals($expectedValue, $actualValue);
    }

    $expectedLength = strlen($expectedValue);
    $actualLength = strlen($actualValue);

    if ($expectedLength !== $actualLength) {
        return false;
    }

    $comparison = 0;
    $index = 0;

    while ($index < $expectedLength) {
        $expectedCharacter = ord($expectedValue[$index]);
        $actualCharacter = ord($actualValue[$index]);
        $comparison = $comparison | ($expectedCharacter ^ $actualCharacter);
        $index = $index + 1;
    }

    return $comparison === 0;
}

function sendNoCacheHeaders(): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

function capturePhpConfiguration(): string
{
    ob_start();
    phpinfo(INFO_CONFIGURATION | INFO_MODULES);
    $phpInfo = ob_get_clean();

    if ($phpInfo === false) {
        return '';
    }

    $bodyStart = stripos($phpInfo, '<body');

    if ($bodyStart === false) {
        return $phpInfo;
    }

    $bodyTagEnd = strpos($phpInfo, '>', $bodyStart);

    if ($bodyTagEnd === false) {
        return $phpInfo;
    }

    $bodyContentStart = $bodyTagEnd + 1;
    $bodyEnd = stripos($phpInfo, '</body>');

    if ($bodyEnd === false) {
        return substr($phpInfo, $bodyContentStart);
    }

    return substr($phpInfo, $bodyContentStart, $bodyEnd - $bodyContentStart);
}

function collectGdInformation(): array
{
    if (function_exists('gd_info') === false) {
        return array();
    }

    $gdInformation = gd_info();

    if (is_array($gdInformation) === false) {
        return array();
    }

    return $gdInformation;
}

function loadHardwareDetails(): string
{
    if (is_file(SITEIMAGEPERSO_HARDWARE_FILE) === false) {
        return '';
    }

    $hardwareDetails = file_get_contents(SITEIMAGEPERSO_HARDWARE_FILE);

    if ($hardwareDetails === false) {
        return '';
    }

    return $hardwareDetails;
}
