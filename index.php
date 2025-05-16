<?php
/**
 * Energy Analytics WordPress Plugin - Development Server
 *
 * This is a placeholder for the development server.
 * In a real WordPress environment, the plugin would be loaded by WordPress.
 */

// Display basic information
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa-IR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Energy Analytics Plugin - توسعه پلاگین</title>
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            direction: rtl;
        }
        h1, h2, h3 {
            color: #0073aa;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .plugin-info {
            background: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .features-list li {
            margin-bottom: 10px;
        }
        .code-sample {
            background: #f8f8f8;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <h1>Energy Analytics WordPress Plugin - پلاگین آنالیز انرژی</h1>
    
    <div class="plugin-info">
        <h2>اطلاعات پلاگین</h2>
        <p><strong>نام:</strong> Energy Analytics</p>
        <p><strong>نسخه:</strong> 1.0.0</p>
        <p><strong>توضیحات:</strong> پلاگین سازمانی برای گزارش‌گیری و تحلیل مصرف انرژی</p>
        <p><strong>نیازمندی‌ها:</strong> PHP 7.4+, WordPress 5.8+</p>
    </div>
    
    <h2>ویژگی‌های اصلی</h2>
    <ul class="features-list">
        <li><strong>ادغام با Advanced Custom Fields (ACF):</strong> امکان تعریف فیلدهای سفارشی برای فرم‌های مصرف انرژی</li>
        <li><strong>آپلود فایل JSON:</strong> آپلود تنظیمات فیلدهای ACF از طریق فایل JSON</li>
        <li><strong>مدیریت گزارش‌ها:</strong> نمایش و فیلتر داده‌های ذخیره شده در جدول wp_energy_logs</li>
        <li><strong>نمودارهای تعاملی:</strong> نمایش داده‌های مصرف انرژی با استفاده از Chart.js</li>
        <li><strong>خروجی PDF:</strong> امکان دریافت خروجی PDF از گزارش‌ها و داشبورد</li>
        <li><strong>اسکریپت‌های سفارشی:</strong> امکان ایجاد و مدیریت اسکریپت‌های جاوااسکریپت برای محاسبات انرژی</li>
        <li><strong>سازگاری با زبان فارسی:</strong> پشتیبانی از فیلدهای فارسی و آپلود فایل‌های JSON فارسی</li>
    </ul>
    
    <h2>نحوه استفاده (در محیط وردپرس)</h2>
    <p>این پلاگین باید در یک محیط وردپرس نصب و فعال شود. به صورت خلاصه:</p>
    <ol>
        <li>فایل‌های پلاگین را در مسیر <code>/wp-content/plugins/energy-analytics/</code> قرار دهید</li>
        <li>به بخش مدیریت پلاگین‌ها بروید و پلاگین Energy Analytics را فعال کنید</li>
        <li>به منوی Energy Analytics در پنل مدیریت دسترسی خواهید داشت</li>
        <li>می‌توانید از طریق Settings > ACF Settings فایل JSON فیلدهای ACF را آپلود کنید</li>
        <li>اطلاعات در جدول wp_energy_logs ذخیره می‌شوند</li>
    </ol>
    
    <h2>نمونه کد PHP برای ثبت داده</h2>
    <div class="code-sample">
        <pre>
// نمونه کد PHP برای ثبت داده در جدول wp_energy_logs
global $wpdb;
$wpdb->insert(
    $wpdb->prefix . 'energy_logs',
    array(
        'form_id' => 'energy_form_1',
        'field_data' => json_encode(array(
            'energy_consumption' => '150.5',
            'source_electricity' => '100.2',
            'source_gas' => '30.3',
            'source_renewable' => '20.0'
        )),
        'user_id' => get_current_user_id(),
        'created_at' => current_time('mysql')
    )
);
        </pre>
    </div>
    
    <h2>ساختار فایل‌های پلاگین</h2>
    <div class="code-sample">
        <pre>
energy-analytics/
├── assets/
│   ├── css/
│   │   └── admin.css
│   ├── js/
│   │   ├── ea-charts.js
│   │   └── script-editor.js
│   └── img/
├── languages/
├── src/
│   ├── Admin/
│   │   ├── Admin.php
│   │   ├── Scripts.php
│   │   └── EnergyReportsTable.php
│   ├── REST/
│   │   └── Endpoints.php
│   └── CLI/
│       └── Commands.php
├── vendor/
├── energy-analytics.php
├── uninstall.php
└── readme.txt
        </pre>
    </div>
    
    <p>توجه: این صفحه تنها یک نمایش از محتوای پلاگین است و در محیط توسعه نمایش داده می‌شود. برای استفاده از قابلیت‌های پلاگین، باید آن را در یک نصب وردپرس راه‌اندازی کنید.</p>
</body>
</html>