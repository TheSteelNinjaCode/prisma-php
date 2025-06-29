<?php

declare(strict_types=1);

namespace PPHP;

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

        if (!file_exists($prismaPHPSettingsJson)) {
            throw new Exception("Settings file not found: $prismaPHPSettingsJson");
        }

        $jsonContent = file_get_contents($prismaPHPSettingsJson);
        $decodedJson = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode JSON: " . json_last_error_msg());
        }

        return new PrismaSettings($decodedJson);
    }

    private static function getRoutesFileList(): array
    {
        $jsonFileName = SETTINGS_PATH . '/files-list.json';
        if (!file_exists($jsonFileName)) {
            return [];
        }

        $jsonContent = file_get_contents($jsonFileName);
        if ($jsonContent === false || empty(trim($jsonContent))) {
            return [];
        }

        $routeFiles = json_decode($jsonContent, true);
        return is_array($routeFiles) ? $routeFiles : [];
    }

    private static function getClassesLogFiles(): array
    {
        $jsonFileName = SETTINGS_PATH . '/class-imports.json';
        if (!file_exists($jsonFileName)) {
            return [];
        }

        $jsonContent = file_get_contents($jsonFileName);
        if ($jsonContent === false || empty(trim($jsonContent))) {
            return [];
        }

        $classLogFiles = json_decode($jsonContent, true);
        return is_array($classLogFiles) ? $classLogFiles : [];
    }

    private static function getIncludeFiles(): array
    {
        $jsonFileName = SETTINGS_PATH . "/request-data.json";
        if (!file_exists($jsonFileName)) {
            return [];
        }

        $jsonContent = file_get_contents($jsonFileName);
        if ($jsonContent === false || empty(trim($jsonContent))) {
            return [];
        }

        $includeFiles = json_decode($jsonContent, true);
        return is_array($includeFiles) ? $includeFiles : [];
    }

    private static function getLocalStorageKey(): string
    {
        $localStorageKey = $_ENV['LOCALSTORE_KEY'] ?? 'pphp_local_store_59e13';
        return strtolower(preg_replace('/\s+/', '_', trim($localStorageKey)));
    }
}
