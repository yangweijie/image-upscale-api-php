# Image Upscale API

基于Real-ESRGAN的图片超分辨率放大API服务。支持2x、3x、4x、6x、8x和16x倍放大。

## 功能特点

- 支持多种放大倍数：2x、3x、4x、6x、8x、16x
- 支持JPEG和PNG格式图片
- 使用Real-ESRGAN进行高质量图片放大
- 使用ImageMagick进行精确缩放
- 支持大文件处理（超时时间1小时）
- 提供进度显示和错误提示

## 访问地址

### Web客户端

访问以下地址使用Web界面上传和处理图片：
```
http://localhost/client.html
```

### API文档（Swagger UI）

访问以下地址查看API文档和进行接口测试：
```
http://localhost/swagger-ui.html
```

## API接口

### 图片放大接口

- **URL**: `/upscale`
- **方法**: POST
- **参数**:
  - `file`: 图片文件（支持jpg/png）
  - `scale`: 放大倍数（2/3/4/6/8/16）
- **返回格式**: JSON
- **返回示例**:
```json
{
    "image_base64": "base64编码的图片数据",
    "image_type": "image/png",
    "message": "Image upscaled successfully",
    "processing_time": "处理时间（秒）"
}
```

## 环境要求

### Windows
- PHP 7.4 或更高版本
- ImageMagick 扩展（可选）
- Vulkan 支持的显卡驱动

### Ubuntu
在 Ubuntu 系统上，需要安装额外的依赖。运行以下命令：

```bash
# 给安装脚本添加执行权限
chmod +x setup_ubuntu.sh

# 运行安装脚本
./setup_ubuntu.sh
```

或者手动安装依赖：
```bash
sudo apt-get update
sudo apt-get install -y libvulkan1 vulkan-tools mesa-vulkan-drivers vulkan-validationlayers
```

### 验证安装
运行以下命令验证 Vulkan 是否正确安装：
```bash
vulkaninfo
```

如果看到 Vulkan 相关信息输出，说明安装成功。

## 注意事项

1. 图片放大处理可能需要较长时间，特别是对于大图片或高倍数放大
2. 服务器和客户端超时时间设置为1小时
3. 建议不要上传过大的图片，以免处理时间过长
4. 6x、8x和16x放大会进行多次处理，需要更长的处理时间

## 错误处理

服务会返回详细的错误信息，包括：
- 文件格式错误
- 放大倍数无效
- 处理超时
- 服务器错误

## 技术栈

- PHP
- Real-ESRGAN
- ImageMagick
- HTML/JavaScript
- Swagger UI
