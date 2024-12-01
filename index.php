<?php
// 设置超时时间为1小时
set_time_limit(3600);
ini_set('max_execution_time', '3600');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 日志函数
function writeLog($message, $type = 'INFO') {
    $logFile = __DIR__ . '/upscale.log';
    $timestamp = date('Y-m-d H:i:s');
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $logMessage = sprintf("[%s][%s][%s] %s%s", $timestamp, $type, $clientIP, $message, PHP_EOL);
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// 如果是OPTIONS请求，直接返回
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    writeLog("Received OPTIONS request");
    http_response_code(200);
    exit();
}

// 错误处理函数
function sendError($message, $code = 400) {
    writeLog($message, 'ERROR');
    http_response_code($code);
    echo json_encode([
        'error' => $message
    ]);
    exit();
}

// 获取Real-ESRGAN可执行文件路径
function getExecutablePath() {
    $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $executable = $is_windows ? './realesrgan-ncnn-vulkan.exe' : './realesrgan-ncnn-vulkan';
    writeLog("Using executable: $executable", 'DEBUG');
    return $executable;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startTime = microtime(true);
    writeLog("Received new upscale request", 'INFO');
    
    // 检查是否有文件上传
    if (!isset($_FILES['file'])) {
        sendError('No file uploaded');
    }

    // 获取放大倍数
    $scale = isset($_POST['scale']) ? intval($_POST['scale']) : 4;
    if (!in_array($scale, [2, 3, 4, 6, 8, 16])) {
        sendError('Scale must be 2, 3, 4, 6, 8, or 16');
    }
    writeLog("Processing with scale: {$scale}x", 'INFO');

    $file = $_FILES['file'];
    $fileSize = round($file['size']/1024/1024, 2); // Convert to MB
    writeLog("Uploaded file details - Name: {$file['name']}, Size: {$fileSize}MB, Type: {$file['type']}", 'INFO');
    
    // 检查文件类型
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    if (!in_array($file['type'], $allowed_types)) {
        sendError('Invalid file type. Only JPG and PNG are allowed.');
    }

    // 创建临时文件
    $input_path = tempnam(sys_get_temp_dir(), 'upscale_input_') . '.png';
    $output_path = tempnam(sys_get_temp_dir(), 'upscale_output_') . '.png';
    writeLog("Created temporary files - Input: $input_path, Output: $output_path", 'DEBUG');

    // 移动上传的文件到临时目录
    if (!move_uploaded_file($file['tmp_name'], $input_path)) {
        sendError('Failed to process uploaded file', 500);
    }
    writeLog("Successfully moved uploaded file to temporary location", 'DEBUG');

    try {
        // 获取可执行文件路径并执行命令
        $executable = getExecutablePath();
        
        // 执行Real-ESRGAN命令
        $cmd = sprintf(
            '"%s" -i "%s" -o "%s" -n realesrnet-x4plus',
            $executable,
            $input_path,
            $output_path
        );
        writeLog("Executing command: $cmd", 'DEBUG');

        $startProcessTime = microtime(true);
        exec($cmd . ' 2>&1', $output, $return_var);
        if ($return_var !== 0) {
            sendError('Failed to process image: ' . implode("\n", $output), 500);
        }

        // 处理不同的放大倍数
        if ($scale != 4) {
            // 获取原始图片尺寸
            $originalSize = getimagesize($input_path);
            if ($originalSize === false) {
                sendError('Failed to get original image size', 500);
            }
            
            // 计算目标尺寸
            $targetWidth = $originalSize[0] * $scale;
            $targetHeight = $originalSize[1] * $scale;
            
            // 对于大于4倍的放大，需要多次应用Real-ESRGAN
            if ($scale > 4) {
                $iterations = ceil(log($scale / 4, 2));  // 计算需要额外运行几次
                $tempPath = $output_path;
                
                for ($i = 0; $i < $iterations; $i++) {
                    $nextPath = tempnam(sys_get_temp_dir(), 'ups') . '.png';
                    
                    // 再次运行Real-ESRGAN
                    $cmd = sprintf(
                        '"%s" -i "%s" -o "%s" -n realesrnet-x4plus',
                        $executable,
                        $tempPath,
                        $nextPath
                    );
                    
                    exec($cmd . ' 2>&1', $output, $return_var);
                    if ($return_var !== 0) {
                        sendError('Failed to process image at iteration ' . ($i + 1), 500);
                    }
                    
                    // 清理上一个临时文件（除了第一次的输出文件）
                    if ($tempPath !== $output_path) {
                        unlink($tempPath);
                    }
                    $tempPath = $nextPath;
                }
                
                // 将最后的结果移动到输出路径
                rename($tempPath, $output_path);
            }
            
            try {
                if (extension_loaded('imagick')) {
                    // 使用ImageMagick处理图片
                    $image = new Imagick($output_path);
                    
                    // 设置图片质量
                    $image->setImageCompressionQuality(100);
                    
                    // 获取 ImageMagick 版本
                    $version = Imagick::getVersion();
                    preg_match('/ImageMagick ([0-9]+\.[0-9]+\.[0-9]+)/', $version['versionString'], $matches);
                    $version = isset($matches[1]) ? $matches[1] : '0.0.0';
                    
                    writeLog("ImageMagick version: {$version}", 'INFO');
                    
                    try {
                        // 尝试使用高级设置
                        if (method_exists($image, 'setImageFilter')) {
                            $image->setOption('filter:support', '2.0');
                            $image->setImageFilter(Imagick::FILTER_LANCZOS);
                            $image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
                        } else {
                            // 使用基本的调整大小方法
                            $image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);
                        }
                        
                        // 保存图片
                        $image->writeImage($output_path);
                        
                        // 清理资源
                        $image->clear();
                        $image->destroy();
                        
                        writeLog("Image resized to {$scale}x using ImageMagick", 'INFO');
                    } catch (ImagickException $e) {
                        writeLog("ImageMagick resize failed: " . $e->getMessage(), 'ERROR');
                        // 如果 ImageMagick 处理失败，回退到 GD
                        throw new Exception("ImageMagick processing failed, falling back to GD");
                    }
                } else {
                    // 使用GD库作为备选方案
                    writeLog("ImageMagick not available, falling back to GD library", 'INFO');
                    
                    $image = imagecreatefrompng($output_path);
                    if ($image === false) {
                        sendError('Failed to load upscaled image', 500);
                    }
                    
                    // 创建新的图片并调整大小
                    $resized = imagecreatetruecolor($targetWidth, $targetHeight);
                    if ($resized === false) {
                        sendError('Failed to create resized image', 500);
                    }
                    
                    // 保持透明度
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    
                    // 调整大小
                    if (!imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($image), imagesy($image))) {
                        sendError('Failed to resize image', 500);
                    }
                    
                    // 保存调整后的图片
                    if (!imagepng($resized, $output_path)) {
                        sendError('Failed to save resized image', 500);
                    }
                    
                    // 清理资源
                    imagedestroy($image);
                    imagedestroy($resized);
                    
                    writeLog("Image resized to {$scale}x using GD Library", 'INFO');
                }
            } catch (Exception $e) {
                sendError('Failed to process image: ' . $e->getMessage(), 500);
            }
        }
        $processTime = round(microtime(true) - $startProcessTime, 2);
        writeLog("Command execution time: {$processTime} seconds", 'INFO');
        writeLog("Command output: " . implode("\n", $output), 'DEBUG');
        writeLog("Command return code: $return_var", 'DEBUG');

        if ($return_var !== 0) {
            sendError('Failed to process image: ' . implode("\n", $output), 500);
        }

        // 读取处理后的图片并转换为base64
        if (!file_exists($output_path)) {
            sendError('Output file not generated', 500);
        }

        $image_data = file_get_contents($output_path);
        $base64_image = base64_encode($image_data);
        $output_size = round(strlen($base64_image)/1024/1024, 2); // Convert to MB
        writeLog("Successfully processed image. Output size: {$output_size}MB", 'INFO');

        $totalTime = round(microtime(true) - $startTime, 2);
        writeLog("Total processing time: {$totalTime} seconds", 'INFO');

        // 返回结果
        echo json_encode([
            'image_base64' => $base64_image,
            'image_type' => 'image/png',
            'message' => 'Image upscaled successfully',
            'processing_time' => $totalTime
        ]);
        writeLog("Response sent successfully", 'INFO');

    } catch (Exception $e) {
        writeLog("Exception occurred: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString(), 'ERROR');
        sendError('Server error: ' . $e->getMessage(), 500);
    } finally {
        // 清理临时文件
        if (file_exists($input_path)) {
            unlink($input_path);
            writeLog("Cleaned up input file: $input_path", 'DEBUG');
        }
        if (file_exists($output_path)) {
            unlink($output_path);
            writeLog("Cleaned up output file: $output_path", 'DEBUG');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $request_uri = $_SERVER['REQUEST_URI'];
    writeLog("Received GET request: $request_uri", 'INFO');
    
    // 处理swagger.json请求
    if (strpos($request_uri, 'swagger.json') !== false) {
        header('Content-Type: application/json');
        echo file_get_contents(__DIR__ . '/swagger.json');
        exit();
    }
    
    // 处理swagger-ui.html请求
    if (strpos($request_uri, 'swagger-ui.html') !== false) {
        header('Content-Type: text/html');
        echo file_get_contents(__DIR__ . '/swagger-ui.html');
        exit();
    }
    
    // 首页路由
    echo json_encode([
        'message' => 'Welcome to Image Upscale API',
        'version' => '1.0.0'
    ]);
} else {
    sendError('Method not allowed', 405);
}
