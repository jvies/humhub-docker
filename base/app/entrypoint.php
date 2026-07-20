<?php
/**
 * HumHub Entrypoint for Distroless / FrankenPHP environments.
 * Replaces the traditional docker-entrypoint.sh without relying on shell/bash binaries.
 */

declare(strict_types=1);

// =====================================================================
// 1. DEFAULT VALUES CONFIGURATION (Mirroring docker-entrypoint.sh)
// =====================================================================

$envDefaults = [
    'HUMHUB_WAIT_FOR_DB'                 => '1',
    'HUMHUB_SET_PJAX'                    => '1',
    'HUMHUB_AUTO_INSTALL'                => 'false',
    'ENTRYPOINT_QUIET_LOGS'              => '',

    'HUMHUB_DB_NAME'                     => 'humhub',
    'HUMHUB_DB_HOST'                     => 'db',
    'HUMHUB_DB_PORT'                     => '3306',
    'HUMHUB_NAME'                        => 'HumHub',
    'HUMHUB_EMAIL'                       => 'humhub@example.com',
    'HUMHUB_LANG'                        => 'en-US',
    'HUMHUB_DEBUG'                       => 'false',

    'HUMHUB_ADMIN_LOGIN'                 => 'admin',
    'HUMHUB_ADMIN_EMAIL'                 => null, // Will default to HUMHUB_EMAIL dynamically below
    'HUMHUB_ADMIN_PASSWORD'              => 'test',

    'HUMHUB_CACHE_CLASS'                 => 'yii\caching\FileCache',
    'HUMHUB_CACHE_EXPIRE_TIME'           => '3600',

    'HUMHUB_ANONYMOUS_REGISTRATION'      => '1',
    'HUMHUB_ALLOW_GUEST_ACCESS'          => '0',
    'HUMHUB_NEED_APPROVAL'               => '0',

    // LDAP Config
    'HUMHUB_LDAP_ENABLED'                => '0',
    'HUMHUB_LDAP_HOSTNAME'               => 'localhost',
    'HUMHUB_LDAP_PORT'                   => '389',
    'HUMHUB_LDAP_ENCRYPTION'             => 'false',
    'HUMHUB_LDAP_USERNAME'               => 'humhub',
    'HUMHUB_LDAP_PASSWORD'               => 'humhub',
    'HUMHUB_LDAP_BASE_DN'                => 'dc=example,dc=com',
    'HUMHUB_LDAP_LOGIN_FILTER'           => '',
    'HUMHUB_LDAP_USER_FILTER'            => '',
    'HUMHUB_LDAP_USERNAME_ATTRIBUTE'     => 'cn',
    'HUMHUB_LDAP_EMAIL_ATTRIBUTE'        => 'mail',
    'HUMHUB_LDAP_ID_ATTRIBUTE'           => 'uid',
    'HUMHUB_LDAP_REFRESH_USERS'          => '1',
    'HUMHUB_LDAP_CACERT'                 => '',
    'HUMHUB_LDAP_SKIP_VERIFY'            => '0',

    // Mailer Config
    'HUMHUB_MAILER_SYSTEM_EMAIL_ADDRESS' => 'noreply@example.com',
    'HUMHUB_MAILER_SYSTEM_EMAIL_NAME'    => 'HumHub',
    'HUMHUB_MAILER_TRANSPORT_TYPE'       => 'php',
    'HUMHUB_MAILER_HOSTNAME'             => 'localhost',
    'HUMHUB_MAILER_PORT'                 => '25',
    'HUMHUB_MAILER_USERNAME'             => '',
    'HUMHUB_MAILER_PASSWORD'             => '',
    'HUMHUB_MAILER_ENCRYPTION'           => '',
    'HUMHUB_MAILER_ALLOW_SELF_SIGNED_CERTS' => '0',

    // Redis Config
    'HUMHUB_REDIS_HOSTNAME'              => '',
    'HUMHUB_REDIS_PORT'                  => '6379',
    'HUMHUB_REDIS_PASSWORD'              => '',
];

// Initialize and apply defaults to the environment if they are not set or are empty
foreach ($envDefaults as $key => $defaultValue) {
    $currentVal = getenv($key);
    if ($currentVal === false || $currentVal === '') {
        // Special dynamic fallback logic
        if ($key === 'HUMHUB_ADMIN_EMAIL') {
            $defaultValue = getenv('HUMHUB_EMAIL') ?: 'humhub@example.com';
        }
        if ($defaultValue !== null) {
            putenv("{$key}={$defaultValue}");
        }
    }
}

// =====================================================================
// 2. HELPER FUNCTIONS & LOGGING
// =====================================================================

$quietLogs = !empty(getenv('ENTRYPOINT_QUIET_LOGS'));

function logMessage(string $message): void {
    global $quietLogs;
    if (!$quietLogs) {
        fwrite(STDOUT, "[entrypoint.php] " . $message . PHP_EOL);
    }
}

function runYii(array $args, string $workingDir = '/app/public/protected'): int {
    $cmd = array_merge(['/usr/local/bin/frankenphp', 'php-cli', $workingDir . '/yii'], $args);
    $escapedCmd = implode(' ', array_map('escapeshellarg', $cmd));
    logMessage("Running Yii: " . $escapedCmd);

    $oldCwd = getcwd();
    chdir($workingDir);
    passthru($escapedCmd, $exitCode);
    if ($oldCwd) {
        chdir($oldCwd);
    }

    return $exitCode;
}

function recursiveCopy(string $src, string $dst): void {
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (is_dir($src . '/' . $file)) {
            recursiveCopy($src . '/' . $file, $dst . '/' . $file);
        } else {
            copy($src . '/' . $file, $dst . '/' . $file);
        }
    }
    closedir($dir);
}

logMessage("Starting pre-launch configuration...");

// =====================================================================
// 3. MAIN PRE-LAUNCH LOGIC
// =====================================================================

$appProtectedDir = '/app/public/protected';
$usrSrcHumhubDir = '/usr/src/humhub';
$dynamicConfigPath = $appProtectedDir . '/config/dynamic.php';
$installedVersionPath = $appProtectedDir . '/config/.version';
$sourceVersionPath = $usrSrcHumhubDir . '/.version';
$commonConfigPath = $appProtectedDir . '/config/common.php';

if (file_exists($dynamicConfigPath)) {
    logMessage("Existing installation found!");

    $installVersion = file_exists($installedVersionPath) ? trim((string)file_get_contents($installedVersionPath)) : '';
    $sourceVersion = file_exists($sourceVersionPath) ? trim((string)file_get_contents($sourceVersionPath)) : '';

    if ($installVersion !== '' && $sourceVersion !== '' && $installVersion !== $sourceVersion) {
        logMessage("Updating HumHub from version {$installVersion} to {$sourceVersion}...");
        runYii(['migrate/up', '--includeModuleMigrations=1', '--interactive=0'], $appProtectedDir);
        copy($sourceVersionPath, $installedVersionPath);
    }
} else {
    logMessage("No existing installation found! Copying source files...");

    if (is_dir($usrSrcHumhubDir . '/protected/config')) {
        recursiveCopy($usrSrcHumhubDir . '/protected/config', $appProtectedDir . '/config');
    }
    if (file_exists($sourceVersionPath)) {
        copy($sourceVersionPath, $installedVersionPath);
    }

    // Generate common.php if missing using common-factory.php
    if (!file_exists($commonConfigPath)) {
        logMessage('Generating common.php...');
        include 'common-config-template.php';
        $content = "<?php\nreturn " . var_export($common, true) . ";\n";

        file_put_contents($commonConfigPath, $content);
    }

    // Ensure runtime log and assets folders exist
    @mkdir($appProtectedDir . '/runtime/logs', 0777, true);
    @touch($appProtectedDir . '/runtime/logs/app.log');
    @mkdir('/app/public/assets', 0777, true);

    // Auto-installation procedure
    $dbUser = getenv('HUMHUB_DB_USER');
    $autoInstall = getenv('HUMHUB_AUTO_INSTALL');

    if (empty($dbUser)) {
        $autoInstall = 'false';
    }

    if ($autoInstall !== 'false') {
        logMessage("Starting HumHub Auto-Installation...");

        runYii(['installer/write-db-config', getenv('HUMHUB_DB_HOST'), getenv('HUMHUB_DB_NAME'), $dbUser, getenv('HUMHUB_DB_PASSWORD')], $appProtectedDir);
        runYii(['installer/install-db'], $appProtectedDir);
        runYii(['installer/write-site-config', getenv('HUMHUB_NAME'), getenv('HUMHUB_EMAIL')], $appProtectedDir);

        // Optional Base URL config
        $proto = getenv('HUMHUB_PROTO');
        $host = getenv('HUMHUB_HOST');
        if (!empty($proto) && !empty($host)) {
            $baseUrl = "{$proto}://{$host}" . (getenv('HUMHUB_SUB_DIR') ?: '') . "/";
            logMessage("Setting base url to: {$baseUrl}");
            runYii(['installer/set-base-url', $baseUrl], $appProtectedDir);
        }

        // Admin account
        runYii(['installer/create-admin-account', getenv('HUMHUB_ADMIN_LOGIN'), getenv('HUMHUB_ADMIN_EMAIL'), getenv('HUMHUB_ADMIN_PASSWORD')], $appProtectedDir);

        // Cache settings
        runYii(['settings/set', 'base', 'cache.class', getenv('HUMHUB_CACHE_CLASS')], $appProtectedDir);
        runYii(['settings/set', 'base', 'cache.expireTime', getenv('HUMHUB_CACHE_EXPIRE_TIME')], $appProtectedDir);

        // Security / User configuration
        runYii(['settings/set', 'user', 'auth.anonymousRegistration', getenv('HUMHUB_ANONYMOUS_REGISTRATION')], $appProtectedDir);
        runYii(['settings/set', 'user', 'auth.allowGuestAccess', getenv('HUMHUB_ALLOW_GUEST_ACCESS')], $appProtectedDir);
        runYii(['settings/set', 'user', 'auth.needApproval', getenv('HUMHUB_NEED_APPROVAL')], $appProtectedDir);

        // LDAP settings block
        if (getenv('HUMHUB_LDAP_ENABLED') !== '0') {
            logMessage("Setting LDAP configuration...");
            $ldapKeys = [
                'enabled' => 'HUMHUB_LDAP_ENABLED', 'hostname' => 'HUMHUB_LDAP_HOSTNAME', 'port' => 'HUMHUB_LDAP_PORT',
                'encryption' => 'HUMHUB_LDAP_ENCRYPTION', 'username' => 'HUMHUB_LDAP_USERNAME', 'password' => 'HUMHUB_LDAP_PASSWORD',
                'baseDn' => 'HUMHUB_LDAP_BASE_DN', 'loginFilter' => 'HUMHUB_LDAP_LOGIN_FILTER', 'userFilter' => 'HUMHUB_LDAP_USER_FILTER',
                'usernameAttribute' => 'HUMHUB_LDAP_USERNAME_ATTRIBUTE', 'emailAttribute' => 'HUMHUB_LDAP_EMAIL_ATTRIBUTE',
                'idAttribute' => 'HUMHUB_LDAP_ID_ATTRIBUTE', 'refreshUsers' => 'HUMHUB_LDAP_REFRESH_USERS'
            ];
            foreach ($ldapKeys as $settingKey => $envVar) {
                runYii(['settings/set', 'ldap', $settingKey, getenv($envVar)], $appProtectedDir);
            }
        }

        // Mailer settings block
        runYii(['settings/set', 'base', 'mailer.systemEmailAddress', getenv('HUMHUB_MAILER_SYSTEM_EMAIL_ADDRESS')], $appProtectedDir);
        runYii(['settings/set', 'base', 'mailer.systemEmailName', getenv('HUMHUB_MAILER_SYSTEM_EMAIL_NAME')], $appProtectedDir);

        if (getenv('HUMHUB_MAILER_TRANSPORT_TYPE') !== 'php') {
            logMessage("Setting Mailer configuration...");
            $mailerKeys = [
                'transportType' => 'HUMHUB_MAILER_TRANSPORT_TYPE', 'hostname' => 'HUMHUB_MAILER_HOSTNAME', 'port' => 'HUMHUB_MAILER_PORT',
                'username' => 'HUMHUB_MAILER_USERNAME', 'password' => 'HUMHUB_MAILER_PASSWORD', 'encryption' => 'HUMHUB_MAILER_ENCRYPTION',
                'allowSelfSignedCerts' => 'HUMHUB_MAILER_ALLOW_SELF_SIGNED_CERTS'
            ];
            foreach ($mailerKeys as $settingKey => $envVar) {
                runYii(['settings/set', 'base', "mailer.{$settingKey}", getenv($envVar)], $appProtectedDir);
            }
        }
    }
}

// =====================================================================
// 4. CONFIG PREPROCESSING & INLINE ADJUSTMENTS
// =====================================================================

logMessage("Config preprocessing...");

$dynamicContent = file_exists($dynamicConfigPath) ? (string)file_get_contents($dynamicConfigPath) : '';
if (str_contains($dynamicContent, "'installed' => true")) {
    logMessage("Installation is active.");

    if (getenv('HUMHUB_SET_PJAX') !== 'false' && file_exists($commonConfigPath)) {
        $commonContent = (string)file_get_contents($commonConfigPath);
        $updatedCommon = str_replace("'enablePjax' => false", "'enablePjax' => true", $commonContent);
        file_put_contents($commonConfigPath, $updatedCommon);
    }

    $trustedHosts = getenv('HUMHUB_TRUSTED_HOSTS');
    $webConfigPath = $appProtectedDir . '/config/web.php';
    if (!empty($trustedHosts) && file_exists($webConfigPath)) {
        $webContent = (string)file_get_contents($webConfigPath);
        $updatedWeb = preg_replace("/'trustedHosts' => \['.*'\]/", "'trustedHosts' => ['$trustedHosts']", $webContent);
        file_put_contents($webConfigPath, $updatedWeb);
    }
} else {
    logMessage("No installation config found or not installed.");
    putenv("HUMHUB_INTEGRITY_CHECK=false");
}

// Toggle Production / Debug mode in public index.php
$indexPath = '/app/public/index.php';
if (file_exists($indexPath)) {
    $indexContent = (string)file_get_contents($indexPath);
    if (getenv('HUMHUB_DEBUG') === 'false') {
        $indexContent = preg_replace('/^(\s*)defined\(\'YII_DEBUG\'\)/m', '$1// defined(\'YII_DEBUG\')', $indexContent);
        $indexContent = preg_replace('/^(\s*)defined\(\'YII_ENV\'\)/m', '$1// defined(\'YII_ENV\')', $indexContent);
        logMessage("Debug disabled in index.php");
    } else {
        $indexContent = preg_replace('/^(\s*)\/\/\s*defined\(\'YII_DEBUG\'\)/m', '$1defined(\'YII_DEBUG\')', $indexContent);
        $indexContent = preg_replace('/^(\s*)\/\/\s*defined\(\'YII_ENV\'\)/m', '$1defined(\'YII_ENV\')', $indexContent);
        logMessage("Debug enabled in index.php");
    }
    file_put_contents($indexPath, $indexContent);
}

// OpenLDAP TLS and CA Cert adjustments
if (getenv('HUMHUB_LDAP_SKIP_VERIFY') !== '0') {
    logMessage("Setting LDAP TLS SKIP VERIFY");
    @mkdir('/etc/openldap', 0755, true);
    file_put_contents('/etc/openldap/ldap.conf', "\nTLS_REQCERT ALLOW\n", FILE_APPEND);
}

$ldapCaCert = getenv('HUMHUB_LDAP_CACERT');
if (!empty($ldapCaCert)) {
    logMessage("Setting LDAP CACERT");
    @mkdir('/etc/ssl/certs', 0755, true);
    file_put_contents('/etc/ssl/certs/cacert.crt', $ldapCaCert);
    @mkdir('/etc/openldap', 0755, true);
    file_put_contents('/etc/openldap/ldap.conf', "\nTLS_CACERT /etc/ssl/certs/cacert.crt\n", FILE_APPEND);
}

// =====================================================================
// 5. EXTENSION HOOKS (/docker-entrypoint.d/*.php)
// =====================================================================

$entrypointDir = '/docker-entrypoint.d';
if (is_dir($entrypointDir)) {
    $files = glob($entrypointDir . '/*.php');
    if (!empty($files)) {
        sort($files);
        logMessage("Found custom configuration scripts in {$entrypointDir}");
        foreach ($files as $script) {
            logMessage("Executing hook: {$script}");
            include $script;
        }
    }
}

logMessage("Entrypoint finished! Launching main process...");

// =====================================================================
// 6. EXECUTION HANDOFF TO MAIN PROCESS (FrankenPHP)
// =====================================================================

$cmd = $argv[1] ?? '/usr/local/bin/frankenphp';
$args = array_slice($argv, 1);

logMessage("Launching main process via pcntl_exec: " . $cmd);

if (function_exists('pcntl_exec')) {
    // pcntl_exec takes the binary path, and then the arguments array
    // WITHOUT including the binary name itself as the first argument.
    pcntl_exec($cmd, array_slice($args, 1));
} else {
    fwrite(STDERR, "[ERROR] pcntl extension is missing! Distroless requires pcntl_exec to launch FrankenPHP." . PHP_EOL);
    exit(1);
}