<?php

declare(strict_types=1);

namespace PP\FileManager;

class UploadFile
{
    protected string $destination = '';
    protected array $messages = [];
    protected array $errorCode = [];
    protected array $successfulUploads = [];
    protected int $maxSize = 51200; // 50KB default
    protected array $permittedTypes = [
        'image/jpeg',
        'image/pjpeg',
        'image/gif',
        'image/png',
        'image/webp'
    ];
    protected string $newName = '';
    protected bool $typeCheckingOn = true;
    protected array $notTrusted = ['bin', 'cgi', 'exe', 'js', 'pl', 'php', 'py', 'sh'];
    protected string $suffix = '.upload';
    protected bool $renameDuplicates = true;

    /**
     * Constructor for the UploadFile class.
     *
     * @param string $uploadFolder The folder to which uploaded files will be moved.
     * @throws \Exception If the upload folder is not a valid, writable folder.
     */
    public function __construct(string $uploadFolder)
    {
        if (!is_dir($uploadFolder) || !is_writable($uploadFolder)) {
            throw new \Exception("$uploadFolder must be a valid, writable folder.");
        }
        // Ensure the folder ends with a '/'
        $this->destination = rtrim($uploadFolder, '/') . '/';
    }

    /**
     * Sets the maximum size for uploaded files.
     *
     * @param int $bytes The maximum size in bytes.
     * @return void
     */
    public function setMaxSize(int $bytes): void
    {
        $serverMax = self::convertToBytes(ini_get('upload_max_filesize'));
        if ($bytes > $serverMax) {
            throw new \Exception('Maximum size cannot exceed server limit for individual files: ' . self::convertFromBytes($serverMax));
        }
        if ($bytes > 0) {
            $this->maxSize = $bytes;
        }
    }

    /**
     * Converts a string value representing a file size to bytes.
     *
     * @param string $val The string value representing the file size.
     * @return int The file size in bytes.
     */
    public static function convertToBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $multiplier = match ($last) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };
        return (int) $val * $multiplier;
    }

    /**
     * Converts the given number of bytes to a human-readable string representation.
     *
     * @param int $bytes The number of bytes to convert.
     * @return string The human-readable string representation of the converted bytes.
     */
    public static function convertFromBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / (1024 * 1024), 1) . ' MB'
            : number_format($bytes / 1024, 1) . ' KB';
    }

    /**
     * Disable type checking and set the allowed file types.
     *
     * @param string|null $suffix The file suffix to allow. If null, the current suffix will be used.
     * @return void
     */
    public function allowAllTypes(?string $suffix = null): void
    {
        $this->typeCheckingOn = false;
        $this->suffix = $suffix ? (strpos($suffix, '.') === 0 ? $suffix : ".$suffix") : $this->suffix;
    }

    /**
     * Uploads file(s) to the server.
     *
     * @param bool $renameDuplicates (optional) Whether to rename duplicate files. Default is true.
     * @return void
     */
    public function upload(bool $renameDuplicates = true): void
    {
        $this->renameDuplicates = $renameDuplicates;
        if (empty($_FILES) || !is_array(current($_FILES))) {
            // No file was uploaded or the structure is invalid, handle this as an error
            $this->messages[] = "No files were uploaded.";
            $this->errorCode[] = UPLOAD_ERR_NO_FILE;
            return;
        }

        $uploaded = current($_FILES);

        // Handle single and multiple file uploads using a unified approach
        $files = is_array($uploaded['name']) ? $this->rearrangeFilesArray($uploaded) : [$uploaded];

        foreach ($files as $file) {
            if ($this->checkFile($file)) {
                $this->moveFile($file);
            }
        }
    }

    /**
     * Updates an existing file by deleting the old file and uploading the new one, using the old file's name.
     *
     * @param array $file The new file information from $_FILES.
     * @param string $oldFilename The name of the file to be replaced.
     * @return bool True if the update was successful, false otherwise.
     */
    public function update(array $file, string $oldFilename): bool
    {
        // First, delete the old file
        if (!$this->delete($oldFilename)) {
            $this->messages[] = "Failed to delete the old file $oldFilename. Update aborted.";
            $this->errorCode[] = UPLOAD_ERR_CANT_WRITE; // Error code for failure to update
            return false;
        }

        // Now proceed to upload the new file with the old filename
        if ($this->checkFile($file)) {
            // Set the new file name to match the old file's name
            $this->newName = $oldFilename;
            $this->moveFile($file);
            return true;
        }

        return false;
    }

    /**
     * Renames a file in the destination folder.
     *
     * @param string $oldName The current name of the file.
     * @param string $newName The new name for the file.
     * @return bool True if the rename was successful, false otherwise.
     */
    public function rename(string $oldName, string $newName): bool
    {
        $oldPath = $this->destination . $oldName;

        // Extract the file extension from the old file
        $extension = pathinfo($oldName, PATHINFO_EXTENSION);

        // Add the extension to the new name
        $newNameWithExtension = str_replace(' ', '_', $newName) . '.' . $extension;
        $newPath = $this->destination . $newNameWithExtension;

        // Check if the file exists
        if (!file_exists($oldPath)) {
            $this->messages[] = "File $oldName does not exist.";
            $this->errorCode[] = UPLOAD_ERR_NO_FILE; // Error code for file not found
            return false;
        }

        // Validate that the new name doesn't already exist
        if (file_exists($newPath)) {
            $this->messages[] = "A file with the name $newNameWithExtension already exists.";
            $this->errorCode[] = UPLOAD_ERR_CANT_WRITE; // Error code for name conflict
            return false;
        }

        // Attempt to rename the file
        if (rename($oldPath, $newPath)) {
            $this->messages[] = "File $oldName renamed successfully to $newNameWithExtension";
            $this->errorCode[] = 0; // Success code
            return true;
        } else {
            $this->messages[] = "Failed to rename $oldName to $newNameWithExtension";
            $this->errorCode[] = UPLOAD_ERR_CANT_WRITE; // Error code for rename failure
            return false;
        }
    }

    /**
     * Deletes a file from the destination folder.
     *
     * @param string $filename The name of the file to delete.
     * @return bool True if the file was deleted, false otherwise.
     */
    public function delete(string $filename): bool
    {
        $filePath = $this->destination . $filename;

        if (!file_exists($filePath)) {
            $this->messages[] = "File $filename does not exist.";
            $this->errorCode[] = UPLOAD_ERR_NO_FILE; // Error code for file not found
            return false;
        }

        if (unlink($filePath)) {
            $this->messages[] = "File $filename deleted successfully.";
            $this->errorCode[] = 0; // Success code
            return true;
        } else {
            $this->messages[] = "Failed to delete $filename.";
            $this->errorCode[] = UPLOAD_ERR_CANT_WRITE; // Error code for failure to delete
            return false;
        }
    }

    /**
     * Retrieves the messages associated with the file upload.
     *
     * @return array The array of messages.
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Retrieves the error codes associated with the file upload.
     *
     * @return array The array of error codes.
     */
    public function getErrorCode(): array
    {
        return $this->errorCode;
    }

    protected function checkFile(array $file): bool
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->getErrorMessage($file);
            return false;
        }
        if (!$this->checkSize($file) || ($this->typeCheckingOn && !$this->checkType($file))) {
            return false;
        }
        $this->checkName($file);
        return true;
    }

    protected function getErrorMessage(array $file): void
    {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => $file['name'] . ' exceeds the maximum size: ' . self::convertFromBytes($this->maxSize),
            UPLOAD_ERR_FORM_SIZE => $file['name'] . ' exceeds the form limit.',
            UPLOAD_ERR_PARTIAL => $file['name'] . ' was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file submitted.',
        ];

        $this->errorCode[] = $file['error'];
        $this->messages[] = $errorMessages[$file['error']] ?? 'Problem uploading ' . $file['name'];
    }

    protected function checkSize(array $file): bool
    {
        if ($file['size'] == 0) {
            $this->messages[] = $file['name'] . ' is empty.';
            $this->errorCode[] = UPLOAD_ERR_NO_FILE; // Log an error code for empty files
            return false;
        }
        if ($file['size'] > $this->maxSize) {
            $this->messages[] = $file['name'] . ' exceeds the maximum size: ' . self::convertFromBytes($this->maxSize);
            $this->errorCode[] = UPLOAD_ERR_INI_SIZE; // Log an error code for exceeding size
            return false;
        }
        return true;
    }

    protected function checkType(array $file): bool
    {
        if (!in_array($file['type'], $this->permittedTypes)) {
            $this->messages[] = $file['name'] . ' is not a permitted type.';
            $this->errorCode[] = UPLOAD_ERR_EXTENSION; // Log an error code for invalid file type
            return false;
        }
        return true;
    }

    protected function checkName(array $file): void
    {
        $this->newName = '';
        $noSpaces = str_replace(' ', '_', $file['name']);
        if ($noSpaces != $file['name']) {
            $this->newName = $noSpaces;
        }
        $nameParts = pathinfo($noSpaces);
        $extension = $nameParts['extension'] ?? '';
        if (!$this->typeCheckingOn && !empty($this->suffix)) {
            if (in_array($extension, $this->notTrusted) || empty($extension)) {
                $this->newName = $noSpaces . $this->suffix;
            }
        }
        if ($this->renameDuplicates) {
            $name = isset($this->newName) ? $this->newName : $file['name'];
            $existing = scandir($this->destination);
            if (in_array($name, $existing)) {
                $i = 1;
                do {
                    $this->newName = $nameParts['filename'] . '_' . $i++;
                    if (!empty($extension)) {
                        $this->newName .= ".$extension";
                    }
                    if (in_array($extension, $this->notTrusted)) {
                        $this->newName .= $this->suffix;
                    }
                } while (in_array($this->newName, $existing));
            }
        }
    }

    protected function moveFile(array $file): void
    {
        // Ensure the newName is set or fallback to the original file name
        $filename = $this->newName ?: $file['name'];
        $destination = $this->destination . $filename;
        $success = move_uploaded_file($file['tmp_name'], $destination);

        if ($success) {
            $message = "{$file['name']} uploaded successfully.";
            if ($this->newName && $this->newName !== $file['name']) {
                $message .= " Renamed to {$this->newName}";
            }

            $this->successfulUploads[] = [
                'original' => $file['name'],
                'final' => $filename
            ];
        } else {
            $message = "Failed to upload {$file['name']}.";
        }

        $this->messages[] = $message;
        // Add a success/error code for file move
        $this->errorCode[] = $success ? 0 : UPLOAD_ERR_CANT_WRITE; // 0 for success, error code for failure
    }

    // Utility function to restructure the $_FILES array for multiple uploads
    protected function rearrangeFilesArray(array $files): array
    {
        $rearranged = [];
        foreach ($files['name'] as $key => $name) {
            $rearranged[] = [
                'name' => $files['name'][$key],
                'type' => $files['type'][$key],
                'tmp_name' => $files['tmp_name'][$key],
                'error' => $files['error'][$key],
                'size' => $files['size'][$key],
            ];
        }
        return $rearranged;
    }

    /**
     * Retrieves the successfully uploaded file names.
     *
     * @return array An array of arrays containing 'original' and 'final' file names.
     */
    public function getSuccessfulUploads(): array
    {
        return $this->successfulUploads;
    }
}
