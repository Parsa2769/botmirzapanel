<?php
// =========================================================================================
// File: index.php
// Path: /index.php (در ریشه پروژه)
// Description: ✨ نقطه ورود اصلی و جدید ربات ddkate.
// این فایل بسیار کوچک و تمیز است و فقط وظیفه مسیردهی درخواست‌ها را بر عهده دارد.
// =========================================================================================

// بارگذاری تنظیمات و کلاس‌های پروژه از طریق Composer Autoloader
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use Ddkate\Controllers\MessageController;
use Ddkate\Controllers\CallbackController;
use Ddkate\Utils\TelegramAPI;
use Ddkate\Utils\UpdateParser;
use Ddkate\Utils\Logger;
use Ddkate\Utils\Helper;

// امنیت: اطمینان از اینکه درخواست فقط از سمت سرورهای تلگرام می‌آید
if (CHECK_TELEGRAM_IP && !Helper::isTelegramIP()) {
    Logger::log("Security Alert: Request from untrusted IP: " . Helper::getClientIP());
    http_response_code(403);
    die("Forbidden: Access is denied.");
}

// دریافت آپدیت از وبهوک تلگرام
$updateData = json_decode(file_get_contents('php://input'), true);
if (!$updateData) {
    exit();
}

// برای دیباگ کردن، می‌توانید تمام آپدیت‌ها را در یک فایل لاگ ذخیره کنید
if (DEBUG_MODE) {
    Logger::log(print_r($updateData, true));
}

try {
    // مسیردهی هوشمند درخواست به کنترلر مناسب
    if (isset($updateData['message'])) {
        $controller = new MessageController($updateData);
        $controller->handle();
    } elseif (isset($updateData['callback_query'])) {
        $controller = new CallbackController($updateData);
        $controller->handle();
    }
} catch (Exception $e) {
    // سیستم مدیریت خطای پیشرفته: هر خطایی در ربات رخ دهد، لاگ شده و به ادمین اطلاع داده می‌شود
    Logger::log("FATAL ERROR: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine());
    
    if (defined('BOT_TOKEN') && defined('ADMIN_ID')) {
        $telegram = new TelegramAPI(BOT_TOKEN);
        $errorMessage = "🚨 **خطای بحرانی در ربات ddkate** 🚨\n\n";
        $errorMessage .= "متن خطا:\n`" . $e->getMessage() . "`\n\n";
        $errorMessage .= "فایل: `" . basename($e->getFile()) . "` (خط: {$e->getLine()})";
        $telegram->sendMessage(ADMIN_ID, $errorMessage, null, 'Markdown');
    }
}
?>
```php
<?php
// =========================================================================================
// File: src/Controllers/MessageController.php
// Path: /src/Controllers/MessageController.php
// Description: 🧠 این کلاس، مغز متفکر ربات برای مدیریت پیام‌های متنی است.
// تمام دستورات کاربر و ادمین که به صورت متن ارسال می‌شوند، در اینجا پردازش می‌شوند.
// =========================================================================================

namespace Ddkate\Controllers;

use Ddkate\Utils\TelegramAPI;
use Ddkate\Utils\UpdateParser;
use Ddkate\Models\User;
use Ddkate\Models\Panel;
use Ddkate\Views\View;
use Ddkate\Models\Setting;

class MessageController {
    private UpdateParser $update;
    private TelegramAPI $telegram;
    private ?array $user;
    private array $settings;

    public function __construct(array $updateData) {
        $this->update = new UpdateParser($updateData);
        $this->telegram = new TelegramAPI(BOT_TOKEN);
        
        // دریافت تنظیمات اصلی از دیتابیس
        $this->settings = Setting::getAll();

        // ثبت یا دریافت اطلاعات کاربر از دیتابیس
        $this->user = User::firstOrCreate($this->update->from_id, [
            'username' => $this->update->username,
            'first_name' => $this->update->first_name
        ]);
        
        // ست کردن ادمین اصلی
        if ($this->update->from_id == ADMIN_ID && !$this->user['is_admin']) {
            User::setAdmin($this->update->from_id, true);
            $this->user['is_admin'] = true;
        }
    }

    public function handle(): void {
        if ($this->checkPrerequisites()) {
            return;
        }
        
        $text = $this->update->text;
        
        if ($this->user['is_admin'] && ($text === '/panel' || $text === '🔑 مدیریت')) {
            (new AdminController($this->update, $this->user, $this->settings))->showMainMenu();
            return;
        }

        switch ($text) {
            case '/start':
                $this->handleStart();
                break;
            case '🛍 خرید سرویس':
                $this->handleBuyService();
                break;
            case '👤 حساب کاربری':
                $this->showAccount();
                break;
            case '📞 پشتیبانی':
                $this->handleSupport();
                break;
            case '📚 راهنما':
                $this->handleHelp();
                break;
            case '🎁 کد هدیه':
                $this->promptForGiftCode();
                break;
            case '🤝 زیرمجموعه‌گیری':
                $this->handleReferral();
                break;
            default:
                $this->handleUserState();
                break;
        }
    }

    private function checkPrerequisites(): bool {
        if ($this->user['is_banned']) {
            $this->telegram->sendMessage($this->update->chat_id, "🚫 متاسفانه شما توسط مدیریت مسدود شده‌اید و امکان استفاده از ربات را ندارید.");
            return true;
        }

        if ($this->settings['channel_lock_status'] === 'on' && !$this->user['is_admin']) {
            $memberStatus = $this->telegram->getChatMember($this->settings['join_channel_id'], $this->update->from_id);
            if(!in_array($memberStatus['result']['status'], ['member', 'creator', 'administrator'])){
                $view = View::forceJoinChannel($this->settings['join_channel_username']);
                $this->telegram->sendMessage($this->update->chat_id, $view['text'], $view['keyboard']);
                return true;
            }
        }

        return false;
    }

    private function handleStart(): void {
        // ... (منطق کامل زیرمجموعه‌گیری در اینجا پیاده‌سازی می‌شود) ...
        User::updateState($this->update->from_id, 'start');
        $view = View::startMessage($this->update->first_name);
        $this->telegram->sendMessage($this->update->chat_id, $view['text'], $view['keyboard'], 'Markdown');
    }
    
    private function handleBuyService(): void {
        $panels = Panel::getActivePanels();
        if (empty($panels)) {
            $this->telegram->sendMessage($this->update->chat_id, "⚠️ متاسفانه در حال حاضر هیچ سروری برای فروش فعال نیست. لطفاً بعداً دوباره تلاش کنید.");
            return;
        }
        $view = View::selectPanelMenu($panels);
        $this->telegram->sendMessage($this->update->chat_id, $view['text'], $view['keyboard']);
    }

    private function showAccount(): void {
        $view = View::accountDetails($this->user);
        $this->telegram->sendMessage($this->update->chat_id, $view['text'], $view['keyboard'], 'Markdown');
    }
    
    private function handleSupport(): void {
        User::updateState($this->update->from_id, 'waiting_support_message');
        $view = View::supportMessagePrompt();
        $this->telegram->sendMessage($this->update->chat_id, $view['text'], $view['keyboard']);
    }

    private function handleHelp(): void {
        // ... (منطق نمایش راهنما) ...
    }

    private function promptForGiftCode(): void {
        User::updateState($this->update->from_id, 'waiting_gift_code');
        $this->telegram->sendMessage($this->update->chat_id, "🎁 لطفاً کد هدیه خود را وارد کنید:");
    }

    private function handleReferral(): void {
        // ... (منطق نمایش اطلاعات زیرمجموعه‌گیری) ...
    }
    
    private function handleUserState(): void {
        $state = $this->user['user_state'];
        if (empty($state) || $state === 'start') {
            $this->telegram->sendMessage($this->update->chat_id, "🤔 دستور شما را متوجه نشدم. لطفاً از دکمه‌های منو استفاده کنید.");
            return;
        }

        if ($state === 'waiting_gift_code') {
            // ... (منطق بررسی و اعمال کد هدیه) ...
        } elseif ($state === 'waiting_support_message') {
            // ... (منطق ارسال پیام به ادمین) ...
        }
    }
}
?>
```php
<?php
// =========================================================================================
// File: src/Controllers/CallbackController.php
// Path: /src/Controllers/CallbackController.php
// Description: این کلاس وظیفه مدیریت تمام کلیک‌ها روی دکمه‌های شیشه‌ای (Inline) را دارد.
// =========================================================================================

namespace Ddkate\Controllers;

use Ddkate\Utils\TelegramAPI;
use Ddkate\Utils\UpdateParser;
use Ddkate\Models\User;
use Ddkate\Models\Panel;
use Ddkate\Models\Product;
use Ddkate\Views\View;

class CallbackController {
    private UpdateParser $update;
    private TelegramAPI $telegram;
    private ?array $user;

    public function __construct(array $updateData) {
        $this->update = new UpdateParser($updateData);
        $this->telegram = new TelegramAPI(BOT_TOKEN);
        $this->user = User::findByChatId($this->update->from_id);
    }

    public function handle(): void {
        if (!$this->user) return;

        $this->telegram->answerCallbackQuery($this->update->callback_query_id);
        $data = $this->update->callback_data;
        
        if (str_starts_with($data, 'select_panel_')) {
            $panelId = (int)str_replace('select_panel_', '', $data);
            $this->showProductsForPanel($panelId);
        } elseif (str_starts_with($data, 'select_product_')) {
            $productId = (int)str_replace('select_product_', '', $data);
            $this->confirmPurchase($productId);
        } elseif (str_starts_with($data, 'confirm_purchase_')) {
            $productId = (int)str_replace('confirm_purchase_', '', $data);
            $this->processPurchase($productId);
        }
    }

    private function showProductsForPanel(int $panelId): void {
        $products = Product::getVisibleProductsByPanel($panelId);
        if (empty($products)) {
            $this->telegram->editMessageText($this->update->chat_id, $this->update->message_id, "😔 متاسفانه در حال حاضر محصولی برای این سرور وجود ندارد.");
            return;
        }
        $view = View::selectProductMenu($products);
        $this->telegram->editMessageText($this->update->chat_id, $this->update->message_id, $view['text'], $view['keyboard']);
    }

    private function confirmPurchase(int $productId): void {
        $product = Product::findById($productId);
        if (!$product) {
            $this->telegram->editMessageText($this->update->chat_id, $this->update->message_id, "❌ خطا: محصول مورد نظر یافت نشد!");
            return;
        }
        User::updateState($this->update->from_id, 'confirming_purchase', json_encode(['product_id' => $productId]));
        $view = View::purchaseConfirmation($product, $this->user);
        $this->telegram->editMessageText($this->update->chat_id, $this->update->message_id, $view['text'], $view['keyboard'], 'Markdown');
    }
    
    private function processPurchase(int $productId): void {
        $product = Product::findById($productId);
        if ($this->user['balance'] < $product['price']) {
            $this->telegram->sendMessage($this->update->chat_id, "💰 موجودی شما کافی نیست! لطفاً ابتدا کیف پول خود را شارژ کنید.");
            return;
        }

        // ... (منطق کامل خرید)
        // 1. کسر هزینه از موجودی کاربر
        // 2. ساخت یوزر در پنل مربوطه (مرزبان، سنایی و...)
        // 3. ذخیره اطلاعات سرویس در جدول invoices
        // 4. ارسال کانفیگ به کاربر
        $this->telegram->editMessageText($this->update->chat_id, $this->update->message_id, "✅ خرید شما با موفقیت انجام شد! اطلاعات سرویس به زودی برای شما ارسال می‌شود.");
    }
}
?>
```php
<?php
// =========================================================================================
// File: src/Views/View.php
// Path: /src/Views/View.php
// Description: 🎨 این کلاس مسئولیت ساخت تمام پیام‌ها و کیبوردهای ربات را دارد.
// تمام متون و اموجی‌ها با ظاهری جذاب در اینجا متمرکز شده‌اند.
// =========================================================================================

namespace Ddkate\Views;

class View {
    public static function startMessage(string $name): array {
        $text = "👋 سلام **{$name}**، به ربات فروش ddkate خوش آمدید!\n\n";
        $text .= "🚀 از طریق منوی زیر می‌توانید به راحتی سرویس مورد نظر خود را تهیه کنید.";
        
        $keyboard = json_encode([
            'keyboard' => [
                [['text' => '🛍 خرید سرویس'], ['text' => '👤 حساب کاربری']],
                [['text' => '📞 پشتیبانی'], ['text' => '📚 راهنما']],
                [['text' => '🎁 کد هدیه'], ['text' => '🤝 زیرمجموعه‌گیری']],
            ],
            'resize_keyboard' => true
        ]);
        return ['text' => $text, 'keyboard' => $keyboard];
    }

    public static function selectPanelMenu(array $panels): array {
        $text = "📍 **انتخاب سرور**\nلطفاً یکی از سرورهای زیر را برای خرید انتخاب کنید:";
        $buttons = [];
        foreach ($panels as $panel) {
            $emoji = '📡';
            if ($panel['type'] === 'sanaei') $emoji = '⚡️';
            elseif ($panel['type'] === 'marzban') $emoji = '🛡️';
            
            $buttons[] = [['text' => $emoji . " " . htmlspecialchars($panel['name']), 'callback_data' => 'select_panel_' . $panel['id']]];
        }
        $buttons[] = [['text' => '« بازگشت', 'callback_data' => 'main_menu']];
        return ['text' => $text, 'keyboard' => json_encode(['inline_keyboard' => $buttons])];
    }
    
    public static function selectProductMenu(array $products): array {
        $text = "📦 **انتخاب محصول**\nلطفاً سرویس مورد نظر خود را انتخاب کنید:";
        $buttons = [];
        foreach ($products as $product) {
            $price = number_format($product['price']);
            $label = "{$product['name']} - {$price} تومان";
            $buttons[] = [['text' => $label, 'callback_data' => 'select_product_' . $product['id']]];
        }
        $buttons[] = [['text' => '« بازگشت به لیست سرورها', 'callback_data' => 'back_to_panels']];
        return ['text' => $text, 'keyboard' => json_encode(['inline_keyboard' => $buttons])];
    }

    public static function purchaseConfirmation(array $product, array $user): array {
        $price = number_format($product['price']);
        $balance = number_format($user['balance']);
        
        $text = "📝 **پیش‌فاکتور خرید**\n\n";
        $text .= "شما در حال خرید سرویس زیر هستید:\n\n";
        $text .= "▫️ **سرویس:** {$product['name']}\n";
        $text .= "▫️ **مدت اعتبار:** {$product['days']} روز\n";
        $text .= "▫️ **حجم:** {$product['data_limit_gb']} گیگابایت\n";
        $text .= "-------------------------------------\n";
        $text .= "💳 **هزینه سرویس:** {$price} تومان\n";
        $text .= "💰 **موجودی شما:** {$balance} تومان\n\n";
        $text .= "آیا خرید را تایید می‌کنید؟";
        
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => '✅ بله، تایید و پرداخت', 'callback_data' => 'confirm_purchase_' . $product['id']]],
                [['text' => '❌ خیر، انصراف', 'callback_data' => 'cancel_purchase']]
            ]
        ]);
        return ['text' => $text, 'keyboard' => $keyboard];
    }
    
    // ... سایر متدهای مربوط به نماها ...
}
?>
