<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;

final class SettingsController
{
    public function index(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureAdmin();

        $savedCount = max(0, (int) ($_GET['saved'] ?? 0));
        $failedCount = max(0, (int) ($_GET['failed'] ?? 0));
        $settings = $this->fetchSystemSettings();

        $navbarStyles = app_unified_navbar_styles();
        $navigation = app_unified_navbar([
            'active' => 'settings',
            'is_admin' => true,
            'show_user_menu' => true,
            'collapse_id' => 'settingsNavbar',
        ]);

        $styles = '<style>.settings-page .settings-card{border:1px solid #dbe7f6;box-shadow:0 .3rem .85rem rgba(15,23,42,.06);} .settings-page .settings-head{background:linear-gradient(120deg,#eef6ff,#e6f7f2);border:1px solid #d8e7f8;border-radius:.9rem;padding:.9rem 1rem;margin-bottom:.9rem;} .settings-page .settings-head .subtitle{margin:.3rem 0 0;color:#334155;font-size:.9rem;} .settings-page .settings-grid{display:grid;grid-template-columns:1fr;gap:.7rem;} .settings-page .setting-item{border:1px solid #e2e8f0;border-radius:.75rem;padding:.75rem .8rem;background:#fff;} .settings-page .setting-meta{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;margin-bottom:.45rem;} .settings-page .setting-key{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.78rem;background:#f1f5f9;border-radius:.4rem;padding:.15rem .4rem;color:#334155;} .settings-page .setting-desc{font-size:.8rem;color:#64748b;margin:.2rem 0 0;} .settings-page .setting-category{font-size:.72rem;color:#0f766e;border:1px solid #99f6e4;background:#f0fdfa;border-radius:999px;padding:.1rem .45rem;} @media (max-width:767.98px){.settings-page .setting-meta{align-items:flex-start;}}</style>';

        $alerts = '';
        if ($savedCount > 0) {
            $alerts .= '<div class="alert alert-success">已保存 ' . $savedCount . ' 项系统设置。</div>';
        }
        if ($failedCount > 0) {
            $alerts .= '<div class="alert alert-warning">有 ' . $failedCount . ' 项设置保存失败（通常是数值格式不正确）。</div>';
        }

        $settingItems = '';
        foreach ($settings as $setting) {
            $id = (int) ($setting['id'] ?? 0);
            $key = (string) ($setting['setting_key'] ?? '');
            $value = (string) ($setting['setting_value'] ?? '');
            $type = (string) ($setting['setting_type'] ?? 'string');
            $category = (string) ($setting['category'] ?? 'general');
            $description = (string) ($setting['description'] ?? '');

            $inputName = 'setting_' . $id;
            if ($type === 'boolean') {
                $checked = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? ' checked' : '';
                $inputHtml = '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . $inputName . '" name="' . $inputName . '" value="1"' . $checked . '><label class="form-check-label" for="' . $inputName . '">启用</label></div>';
            } elseif ($type === 'number') {
                $inputHtml = '<input class="form-control" id="' . $inputName . '" name="' . $inputName . '" type="number" step="any" value="' . htmlspecialchars($value, ENT_QUOTES) . '">';
            } else {
                $inputHtml = '<input class="form-control" id="' . $inputName . '" name="' . $inputName . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '">';
            }

            $settingItems .= '<div class="setting-item">'
                . '<div class="setting-meta">'
                . '<span class="setting-category">' . htmlspecialchars($category, ENT_QUOTES) . '</span>'
                . '<span class="badge text-bg-light border">' . htmlspecialchars($type, ENT_QUOTES) . '</span>'
                . '<span class="setting-key">' . htmlspecialchars($key, ENT_QUOTES) . '</span>'
                . '</div>'
                . $inputHtml
                . ($description !== '' ? '<p class="setting-desc">' . htmlspecialchars($description, ENT_QUOTES) . '</p>' : '')
                . '</div>';
        }

        $content = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>系统设置</title><link rel="stylesheet" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/bootstrap-icons.css">'
            . $navbarStyles
            . $styles
            . '</head><body>'
            . $navigation
            . '<div class="container mt-4 settings-page">'
            . '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">'
            . '<h3 class="mb-2">系统设置</h3>'
            . '<a href="/dashboard" class="btn btn-outline-secondary mb-2">返回仪表板</a>'
            . '</div>'
            . '<div class="settings-head"><h5 class="mb-0">配置与巡检入口</h5><p class="subtitle">此页面提供系统管理入口，并支持直接维护数据库 system_settings 配置项。</p></div>'
            . $alerts
            . '<div class="row g-3">'
            . '<div class="col-md-4"><a class="card text-decoration-none h-100" href="/api-tokens"><div class="card-body"><h5 class="card-title"><i class="bi bi-key-fill me-2"></i>API 令牌管理</h5><p class="card-text text-muted mb-0">创建、吊销与轮换 API Token。</p></div></a></div>'
            . '<div class="col-md-4"><a class="card text-decoration-none h-100" href="/api-access-logs"><div class="card-body"><h5 class="card-title"><i class="bi bi-journal-text me-2"></i>API 访问审计</h5><p class="card-text text-muted mb-0">查看访问日志、筛选与导出。</p></div></a></div>'
            . '<div class="col-md-4"><a class="card text-decoration-none h-100" href="/notifications"><div class="card-body"><h5 class="card-title"><i class="bi bi-bell-fill me-2"></i>通知中心</h5><p class="card-text text-muted mb-0">查看提醒并执行批量已读操作。</p></div></a></div>'
            . '</div>'
            . '<div class="card settings-card mt-3"><div class="card-body">'
            . '<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"><h5 class="mb-0">系统参数（system_settings）</h5><span class="badge text-bg-light border">共 ' . count($settings) . ' 项</span></div>'
            . '<form method="POST" action="/settings">'
            . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
            . '<div class="settings-grid">' . $settingItems . '</div>'
            . '<div class="d-flex justify-content-end mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>保存系统设置</button></div>'
            . '</form>'
            . '</div></div>'
            . '</div></body></html>';

        return Response::html($content);
    }

    public function update(): Response
    {
        $this->ensureAuthenticated();
        $this->ensureAdmin();

        if (!session()->validateToken($_POST['_token'] ?? '')) {
            throw HttpException::forbidden('CSRF令牌无效');
        }

        $settings = $this->fetchSystemSettings();
        $saved = 0;
        $failed = 0;

        foreach ($settings as $setting) {
            $id = (int) ($setting['id'] ?? 0);
            $type = (string) ($setting['setting_type'] ?? 'string');
            $inputName = 'setting_' . $id;

            if ($type === 'boolean') {
                $value = isset($_POST[$inputName]) ? 'true' : 'false';
            } else {
                if (!array_key_exists($inputName, $_POST)) {
                    continue;
                }

                $rawValue = trim((string) $_POST[$inputName]);
                if ($type === 'number' && $rawValue !== '' && !is_numeric($rawValue)) {
                    $failed++;
                    continue;
                }
                $value = $rawValue;
            }

            $updated = db()->update('system_settings', [
                'setting_value' => $value,
                'updated_by' => (int) auth()->id(),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);

            if ($updated > 0) {
                $saved++;
            }
        }

        return Response::redirect('/settings?saved=' . $saved . '&failed=' . $failed);
    }

    private function fetchSystemSettings(): array
    {
        return db()->fetchAll(
            'SELECT id, setting_key, setting_value, setting_type, category, description
             FROM system_settings
             ORDER BY category ASC, setting_key ASC'
        );
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }

    private function ensureAdmin(): void
    {
        if (!auth()->isAdmin()) {
            throw HttpException::forbidden('仅管理员可访问系统设置');
        }
    }
}
