<?php
require_once('..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
\Model\Core\Model::init();

require_once('..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'PageBuilder' . DIRECTORY_SEPARATOR . 'config.php');

$url = null;

try {
	if (!isset($_FILES['upload']))
		throw new \Exception('No file detected');

	$uploadPath = $config['upload-path'] ?? 'app-data/img/page-builder/';
	$uploadPath = trim($uploadPath, '/') . '/';

	$diskPath = '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadPath);
	if (!is_dir($diskPath))
		mkdir($diskPath, 0777, true);

	if ($_FILES['upload']['error'] == UPLOAD_ERR_OK and is_uploaded_file($_FILES['upload']['tmp_name'])) {
		$ext = pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION);

		do {
			$filename = mt_rand();
		} while (file_exists($diskPath . $filename . '.' . $ext));

		if (move_uploaded_file($_FILES['upload']['tmp_name'], $diskPath . $filename . '.' . $ext)) {
			$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
			if (in_array(strtolower($ext), $imageExts)) {
				try {
					$image = new \Model\Images\Image($diskPath . $filename . '.' . $ext);
					$image->save($diskPath . $filename . '.' . $ext);
					$image->destroy();
				} catch (\Throwable $e) {
				}
			}

			$host = '';
			if ($config['include-host-in-uploads']) {
				if (!defined('HTTPS')) {
					if ((!empty($_SERVER['HTTPS']) and $_SERVER['HTTPS'] !== 'off') or ($_SERVER['SERVER_PORT'] ?? null) == 443)
						define('HTTPS', 1);
					else
						define('HTTPS', 0);
				}

				$host = (HTTPS ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
			}

			$url = $host . PATH . $uploadPath . $filename . '.' . $ext;
		} else {
			throw new \Exception('Error in saving file, please retry');
		}
	} else {
		throw new \Exception('Error ' . $_FILES['upload']['error'] . ' in uploading file, please retry');
	}

	echo json_encode(['url' => $url]);
} catch (\Throwable $e) {
	http_response_code(500);

	echo json_encode([
		'error' => ['message' => $e->getMessage()],
	]);
}
