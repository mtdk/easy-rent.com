/**
 * 收租管理系统 - 自定义JavaScript
 * 版本: 1.0.0
 * 描述: 系统通用功能、工具函数和UI交互
 */

// 命名空间
const EasyRent = {
    // 配置
    config: {
        apiBaseUrl: '/api',
        localStoragePrefix: 'easyrent_',
        debug: true
    },

    // 工具函数
    utils: {
        /**
         * 格式化日期
         * @param {Date|string} date - 日期对象或字符串
         * @param {string} format - 格式字符串 (默认: YYYY-MM-DD)
         * @returns {string} 格式化后的日期
         */
        formatDate: function (date, format = 'YYYY-MM-DD') {
            const d = date instanceof Date ? date : new Date(date);
            if (isNaN(d.getTime())) return '无效日期';

            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const hours = String(d.getHours()).padStart(2, '0');
            const minutes = String(d.getMinutes()).padStart(2, '0');
            const seconds = String(d.getSeconds()).padStart(2, '0');

            return format
                .replace('YYYY', year)
                .replace('MM', month)
                .replace('DD', day)
                .replace('HH', hours)
                .replace('mm', minutes)
                .replace('ss', seconds);
        },

        /**
         * 格式化货币
         * @param {number} amount - 金额
         * @param {string} currency - 货币符号 (默认: ¥)
         * @returns {string} 格式化后的货币字符串
         */
        formatCurrency: function (amount, currency = '¥') {
            if (isNaN(amount)) return `${currency}0.00`;
            return `${currency}${amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}`;
        },

        /**
         * 显示Toast通知
         * @param {string} message - 消息内容
         * @param {string} type - 类型: success, error, warning, info
         * @param {number} duration - 显示时长(毫秒)
         */
        showToast: function (message, type = 'info', duration = 3000) {
            // 兼容历史调用：error 在 Bootstrap 中对应 danger
            const normalizedType = type === 'error' ? 'danger' : type;

            // 创建Toast容器（如果不存在）
            let toastContainer = document.getElementById('easyrent-toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'easyrent-toast-container';
                toastContainer.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    max-width: 350px;
                `;
                document.body.appendChild(toastContainer);
            }

            // 创建Toast元素
            const toast = document.createElement('div');
            toast.className = `alert alert-${normalizedType} alert-dismissible fade show`;
            if (normalizedType === 'danger') {
                toast.classList.add('easyrent-toast-danger');
            }
            toast.style.cssText = `
                margin-bottom: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                animation: slideInRight 0.3s ease-out;
            `;

            // 添加CSS动画
            if (!document.getElementById('toast-animations')) {
                const style = document.createElement('style');
                style.id = 'toast-animations';
                style.textContent = `
                    @keyframes slideInRight {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes fadeOut {
                        from { opacity: 1; }
                        to { opacity: 0; }
                    }

                    .easyrent-toast-danger {
                        background: #8b1117;
                        border: 1px solid #dc3545;
                        color: #fff;
                        box-shadow: 0 10px 24px rgba(220, 53, 69, 0.35) !important;
                        position: relative;
                        padding-left: 14px;
                    }

                    .easyrent-toast-danger::before {
                        content: "";
                        position: absolute;
                        left: 0;
                        top: 0;
                        bottom: 0;
                        width: 4px;
                        background: #ffb3b8;
                    }

                    .easyrent-toast-danger .btn-close {
                        filter: invert(1) grayscale(1) brightness(200%);
                    }
                `;
                document.head.appendChild(style);
            }

            // 设置Toast内容
            const iconMap = {
                success: 'bi-check-circle-fill',
                danger: 'bi-exclamation-octagon-fill',
                warning: 'bi-exclamation-triangle-fill',
                info: 'bi-info-circle-fill'
            };

            toast.innerHTML = `
                <i class="bi ${iconMap[normalizedType] || 'bi-info-circle-fill'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            // 添加到容器
            toastContainer.appendChild(toast);

            // 自动移除
            setTimeout(() => {
                toast.style.animation = 'fadeOut 0.3s ease-out';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, duration);
        },

        /**
         * 验证表单字段
         * @param {HTMLElement} input - 输入元素
         * @param {Object} rules - 验证规则
         * @returns {boolean} 是否验证通过
         */
        validateField: function (input, rules) {
            const value = input.value.trim();
            let isValid = true;
            let errorMessage = '';

            // 必填验证
            if (rules.required && !value) {
                isValid = false;
                errorMessage = rules.requiredMessage || '此字段为必填项';
            }

            // 最小长度验证
            if (isValid && rules.minLength && value.length < rules.minLength) {
                isValid = false;
                errorMessage = rules.minLengthMessage || `至少需要${rules.minLength}个字符`;
            }

            // 最大长度验证
            if (isValid && rules.maxLength && value.length > rules.maxLength) {
                isValid = false;
                errorMessage = rules.maxLengthMessage || `不能超过${rules.maxLength}个字符`;
            }

            // 正则表达式验证
            if (isValid && rules.pattern && !rules.pattern.test(value)) {
                isValid = false;
                errorMessage = rules.patternMessage || '格式不正确';
            }

            // 显示/清除错误信息
            const feedback = input.nextElementSibling;
            if (feedback && feedback.classList.contains('invalid-feedback')) {
                if (!isValid) {
                    feedback.textContent = errorMessage;
                    input.classList.add('is-invalid');
                    input.classList.remove('is-valid');
                } else {
                    feedback.textContent = '';
                    input.classList.remove('is-invalid');
                    input.classList.add('is-valid');
                }
            }

            return isValid;
        },

        /**
         * 从本地存储获取数据
         * @param {string} key - 存储键名
         * @param {any} defaultValue - 默认值
         * @returns {any} 存储的值或默认值
         */
        getLocalStorage: function (key, defaultValue = null) {
            try {
                const fullKey = `${EasyRent.config.localStoragePrefix}${key}`;
                const item = localStorage.getItem(fullKey);
                return item ? JSON.parse(item) : defaultValue;
            } catch (error) {
                console.error('读取本地存储失败:', error);
                return defaultValue;
            }
        },

        /**
         * 保存数据到本地存储
         * @param {string} key - 存储键名
         * @param {any} value - 要存储的值
         */
        setLocalStorage: function (key, value) {
            try {
                const fullKey = `${EasyRent.config.localStoragePrefix}${key}`;
                localStorage.setItem(fullKey, JSON.stringify(value));
            } catch (error) {
                console.error('保存到本地存储失败:', error);
            }
        },

        /**
         * 从本地存储移除数据
         * @param {string} key - 存储键名
         */
        removeLocalStorage: function (key) {
            try {
                const fullKey = `${EasyRent.config.localStoragePrefix}${key}`;
                localStorage.removeItem(fullKey);
            } catch (error) {
                console.error('从本地存储移除失败:', error);
            }
        }
    },

    // API请求模块
    api: {
        /**
         * 发送API请求
         * @param {string} endpoint - API端点
         * @param {Object} options - 请求选项
         * @returns {Promise} Promise对象
         */
        request: async function (endpoint, options = {}) {
            const url = `${EasyRent.config.apiBaseUrl}${endpoint}`;
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'include'
            };

            const mergedOptions = { ...defaultOptions, ...options };

            if (options.body && typeof options.body === 'object') {
                mergedOptions.body = JSON.stringify(options.body);
            }

            try {
                const response = await fetch(url, mergedOptions);

                if (!response.ok) {
                    throw new Error(`HTTP错误: ${response.status}`);
                }

                const data = await response.json();
                return data;
            } catch (error) {
                console.error('API请求失败:', error);
                throw error;
            }
        },

        /**
         * 用户登录
         * @param {string} username - 用户名
         * @param {string} password - 密码
         * @returns {Promise} Promise对象
         */
        login: async function (username, password) {
            return this.request('/auth/login', {
                method: 'POST',
                body: { username, password }
            });
        },

        /**
         * 用户登出
         * @returns {Promise} Promise对象
         */
        logout: async function () {
            return this.request('/auth/logout', {
                method: 'POST'
            });
        },

        /**
         * 获取当前用户信息
         * @returns {Promise} Promise对象
         */
        getCurrentUser: async function () {
            return this.request('/auth/me');
        }
    },

    // 用户认证模块
    auth: {
        /**
         * 检查用户是否已登录
         * @returns {boolean} 是否已登录
         */
        isLoggedIn: function () {
            return !!EasyRent.utils.getLocalStorage('user_token');
        },

        /**
         * 获取用户令牌
         * @returns {string|null} 用户令牌
         */
        getToken: function () {
            return EasyRent.utils.getLocalStorage('user_token');
        },

        /**
         * 保存用户令牌
         * @param {string} token - 用户令牌
         */
        setToken: function (token) {
            EasyRent.utils.setLocalStorage('user_token', token);
        },

        /**
         * 清除用户认证信息
         */
        clearAuth: function () {
            EasyRent.utils.removeLocalStorage('user_token');
            EasyRent.utils.removeLocalStorage('user_info');
        },

        /**
         * 重定向到登录页面
         */
        redirectToLogin: function () {
            window.location.href = '/login.html';
        }
    },

    // UI组件模块
    components: {
        /**
         * 初始化所有工具提示
         */
        initTooltips: function () {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        },

        /**
         * 初始化所有弹出框
         */
        initPopovers: function () {
            const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
        },

        /**
         * 显示加载指示器
         * @param {string} message - 加载消息
         */
        showLoading: function (message = '加载中...') {
            // 创建加载遮罩（如果不存在）
            let loadingOverlay = document.getElementById('easyrent-loading-overlay');
            if (!loadingOverlay) {
                loadingOverlay = document.createElement('div');
                loadingOverlay.id = 'easyrent-loading-overlay';
                loadingOverlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(255, 255, 255, 0.8);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    z-index: 99999;
                    backdrop-filter: blur(2px);
                `;

                const spinner = document.createElement('div');
                spinner.className = 'spinner-border text-primary';
                spinner.style.width = '3rem';
                spinner.style.height = '3rem';
                spinner.setAttribute('role', 'status');

                const srOnly = document.createElement('span');
                srOnly.className = 'visually-hidden';
                srOnly.textContent = '加载中...';

                spinner.appendChild(srOnly);
                loadingOverlay.appendChild(spinner);

                const messageDiv = document.createElement('div');
                messageDiv.className = 'mt-3 text-primary fw-bold';
                messageDiv.textContent = message;
                loadingOverlay.appendChild(messageDiv);

                document.body.appendChild(loadingOverlay);
            } else {
                loadingOverlay.style.display = 'flex';
            }
        },

        /**
         * 隐藏加载指示器
         */
        hideLoading: function () {
            const loadingOverlay = document.getElementById('easyrent-loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
        },

        /**
         * 显示确认对话框
         * @param {string} title - 对话框标题
         * @param {string} message - 对话框消息
         * @param {Function} onConfirm - 确认回调函数
         * @param {Function} onCancel - 取消回调函数
         */
        showConfirmDialog: function (title, message, onConfirm, onCancel = null) {
            // 创建对话框（如果不存在）
            let dialog = document.getElementById('easyrent-confirm-dialog');
            if (!dialog) {
                dialog = document.createElement('div');
                dialog.id = 'easyrent-confirm-dialog';
                dialog.className = 'modal fade';
                dialog.setAttribute('tabindex', '-1');

                dialog.innerHTML = `
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="easyrent-confirm-title"></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="easyrent-confirm-message"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="easyrent-confirm-cancel">取消</button>
                                <button type="button" class="btn btn-primary" id="easyrent-confirm-ok">确定</button>
                            </div>
                        </div>
                    </div>
                `;

                document.body.appendChild(dialog);
            }

            // 设置内容
            document.getElementById('easyrent-confirm-title').textContent = title;
            document.getElementById('easyrent-confirm-message').textContent = message;

            // 显示对话框
            const modal = new bootstrap.Modal(dialog);
            modal.show();

            // 绑定事件
            const confirmBtn = document.getElementById('easyrent-confirm-ok');
            const cancelBtn = document.getElementById('easyrent-confirm-cancel');

            const handleConfirm = () => {
                if (onConfirm) onConfirm();
                modal.hide();
            };

            const handleCancel = () => {
                if (onCancel) onCancel();
                modal.hide();
            };

            // 移除旧的事件监听器
            confirmBtn.replaceWith(confirmBtn.cloneNode(true));
            cancelBtn.replaceWith(cancelBtn.cloneNode(true));

            // 添加新的事件监听器
            document.getElementById('easyrent-confirm-ok').addEventListener('click', handleConfirm);
            document.getElementById('easyrent-confirm-cancel').addEventListener('click', handleCancel);

            // 对话框隐藏时清理
            dialog.addEventListener('hidden.bs.modal', function () {
                // 清理事件监听器
                document.getElementById('easyrent-confirm-ok').removeEventListener('click', handleConfirm);
                document.getElementById('easyrent-confirm-cancel').removeEventListener('click', handleCancel);
            });
        }
    },

    // 初始化函数
    init: function () {
        // 初始化UI组件
        this.components.initTooltips();
        this.components.initPopovers();

        // 全局错误处理
        window.addEventListener('error', function (event) {
            console.error('全局错误:', event.error);
            EasyRent.utils.showToast('发生错误，请检查控制台', 'error');
        });

        // 全局未处理的Promise拒绝
        window.addEventListener('unhandledrejection', function (event) {
            console.error('未处理的Promise拒绝:', event.reason);
            EasyRent.utils.showToast('请求失败，请稍后重试', 'error');
        });

        // 调试信息
        if (this.config.debug) {
            console.log('收租管理系统JS已初始化');
            console.log('配置:', this.config);
            console.log('认证状态:', this.auth.isLoggedIn() ? '已登录' : '未登录');
            console.log('Bootstrap资源: 已本地化');
        }
    }
};

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function () {
    EasyRent.init();
});

// 导出到全局作用域
window.EasyRent = EasyRent;