#!/bin/bash

# 更新包列表
sudo apt-get update

# 安装Vulkan相关包
sudo apt-get install -y \
    libvulkan1 \
    vulkan-tools \
    mesa-vulkan-drivers \
    vulkan-validationlayers

# 安装其他可能需要的依赖
sudo apt-get install -y \
    libc6 \
    libstdc++6 \
    libgcc1

# 验证安装
vulkaninfo

echo "Setup completed. If you see Vulkan information above, the installation was successful."
