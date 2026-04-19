<?php

declare(strict_types=1);

namespace PP;

use Exception;

class BSPathRewrite
{
    public string $pattern;
    public string $replacement;

    public function __construct(array $data)
    {
        $this->pattern = $data['pattern'] ?? '';
        $this->replacement = $data['replacement'] ?? '';
    }
}

class PrismaSettings
{
    public string $projectName;
    public string $projectRootPath;
    public string $phpEnvironment;
    public string $phpRootPathExe;
    public string $phpGenerateClassPath;
    public string $bsTarget;
    public BSPathRewrite $bsPathRewrite;
    public bool $backendOnly;
    public bool $swaggerDocs;
    public bool $tailwindcss;
    public bool $websocket;
    public bool $prisma;
    public bool $docker;
    public string $version;
    public array $excludeFiles;

    public function __construct(array $data)
    {
        $this->projectName = $data['projectName'] ?? '';
        $this->projectRootPath = $data['projectRootPath'] ?? '';
        $this->phpEnvironment = $data['phpEnvironment'] ?? '';
        $this->phpRootPathExe = $data['phpRootPathExe'] ?? '';
        $this->bsTarget = $data['bsTarget'] ?? '';
        $this->bsPathRewrite = new BSPathRewrite($data['bsPathRewrite'] ?? []);
        $this->backendOnly = $data['backendOnly'] ?? false;
        $this->swaggerDocs = $data['swaggerDocs'] ?? false;
        $this->tailwindcss = $data['tailwindcss'] ?? false;
        $this->websocket = $data['websocket'] ?? false;
        $this->prisma = $data['prisma'] ?? false;
        $this->docker = $data['docker'] ?? false;
        $this->version = $data['version'] ?? '';
        $this->excludeFiles = $data['excludeFiles'] ?? [];
    }
}

class PrismaPHPSettings
{
    /**
     * @var array<string, array>
     */
    private static array $jsonFileCache = [];

    /**
     * The settings from the prisma-php.json file.
     * 
     * @var PrismaSettings
     */
    public static PrismaSettings $option;

    /**
     * The list of route files from the files-list.json file.
     * 
     * @var array
     */
    public static array $routeFiles = [];

    /**
     * The list of class log files.
     * 
     * @var array
     */
    public static array $classLogFiles = [];

    /**
     * The list of include files.
     *
     * @var array
     */
    public static array $includeFiles = [];

    /**
     * The local storage key for the app state.
     *
     * @var string
     */
    public static string $localStoreKey;

    public static function init(): void
    {
        self::$option = self::getPrismaSettings();
        self::$routeFiles = self::getRoutesFileList();
        self::$classLogFiles = self::getClassesLogFiles();
        self::$includeFiles = self::getIncludeFiles();
        self::$localStoreKey = self::getLocalStorageKey();
    }

    /**
     * Get Prisma settings from the JSON file.
     *
     * @return PrismaSettings
     * @throws Exception if the JSON file cannot be decoded.
     */
    private static function getPrismaSettings(): PrismaSettings
    {
        $prismaPHPSettingsJson = DOCUMENT_PATH . '/prisma-php.json';

        return new PrismaSettings(self::readJsonFile($prismaPHPSettingsJson, true));
    }

    private static function getRoutesFileList(): array
    {
        return self::readJsonFile(SETTINGS_PATH . '/files-list.json');
    }

    private static function getClassesLogFiles(): array
    {
        return self::readJsonFile(SETTINGS_PATH . '/class-imports.json');
    }

    private static function getIncludeFiles(): array
    {
        return self::readJsonFile(SETTINGS_PATH . '/request-data.json');
    }

    private static function getLocalStorageKey(): string
    {
        $localStorageKey = Env::string('LOCALSTORE_KEY', 'pp_local_store_59e13');
        return strtolower(preg_replace('/\s+/', '_', trim($localStorageKey)));
    }

    /**
     * @return array<mixed>
     */
    private static function readJsonFile(string $filePath, bool $throwOnInvalid = false): array
    {
        if (array_key_exists($filePath, self::$jsonFileCache)) {
            return self::$jsonFileCache[$filePath];
        }

        if (!is_file($filePath)) {
            if ($throwOnInvalid) {
                throw new Exception("Settings file not found: $filePath");
            }

            self::$jsonFileCache[$filePath] = [];

            return self::$jsonFileCache[$filePath];
        }

        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false || trim($jsonContent) === '') {
            if ($throwOnInvalid) {
                throw new Exception("Failed to decode JSON: Syntax error");
            }

            self::$jsonFileCache[$filePath] = [];

            return self::$jsonFileCache[$filePath];
        }

        $decodedJson = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($throwOnInvalid) {
                throw new Exception('Failed to decode JSON: ' . json_last_error_msg());
            }

            self::$jsonFileCache[$filePath] = [];

            return self::$jsonFileCache[$filePath];
        }

        if (!is_array($decodedJson)) {
            if ($throwOnInvalid) {
                throw new Exception('Failed to decode JSON: Expected a JSON object or array');
            }

            self::$jsonFileCache[$filePath] = [];

            return self::$jsonFileCache[$filePath];
        }

        self::$jsonFileCache[$filePath] = $decodedJson;

        return self::$jsonFileCache[$filePath];
    }
}
