<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain;

use Cronos\Settings\Domain\Exceptions\UnknownSetting;
use Cronos\Settings\Domain\ValueObjects\SettingDefinition;

/** Catalogue of every setting the platform understands. Add new keys here (and only here). */
final class SettingDefinitions
{
    /** @var array<string, SettingDefinition>|null */
    private static ?array $byId = null;

    /** @return array<string, SettingDefinition> keyed by "group.key" */
    public static function all(): array
    {
        return self::$byId ??= collect([
            new SettingDefinition('identity', 'store_name', 'string', 'Nombre de la tienda', translatable: true, public: true),
            new SettingDefinition('identity', 'tagline', 'string', 'Eslogan', translatable: true, public: true),
            new SettingDefinition('identity', 'logo', 'media', 'Logotipo', translatable: false, public: true),
            new SettingDefinition('identity', 'logo_dark', 'media', 'Logotipo (fondo oscuro)', translatable: false, public: true),
            new SettingDefinition('identity', 'favicon', 'media', 'Favicon', translatable: false, public: true),
            new SettingDefinition('contact', 'email', 'email', 'Correo de contacto', translatable: false, public: true),
            new SettingDefinition('contact', 'phone', 'phone', 'Teléfono', translatable: false, public: true),
            new SettingDefinition('contact', 'whatsapp', 'phone', 'WhatsApp', translatable: false, public: true),
            new SettingDefinition('contact', 'address', 'text', 'Dirección', translatable: true, public: true),
            new SettingDefinition('contact', 'business_hours', 'text', 'Horario de atención', translatable: true, public: true),
            new SettingDefinition('social', 'facebook', 'url', 'Facebook', translatable: false, public: true),
            new SettingDefinition('social', 'instagram', 'url', 'Instagram', translatable: false, public: true),
            new SettingDefinition('social', 'tiktok', 'url', 'TikTok', translatable: false, public: true),
            new SettingDefinition('social', 'youtube', 'url', 'YouTube', translatable: false, public: true),
            new SettingDefinition('social', 'pinterest', 'url', 'Pinterest', translatable: false, public: true),
            new SettingDefinition('legal', 'terms', 'text', 'Términos y condiciones', translatable: true, public: true),
            new SettingDefinition('legal', 'privacy', 'text', 'Aviso de privacidad', translatable: true, public: true),
            new SettingDefinition('legal', 'returns', 'text', 'Política de devoluciones', translatable: true, public: true),
            new SettingDefinition('legal', 'shipping_policy', 'text', 'Política de envíos', translatable: true, public: true),
            new SettingDefinition('seo', 'title_template', 'string', 'Plantilla de título (usa %s)', translatable: true, public: true),
            new SettingDefinition('seo', 'default_description', 'text', 'Descripción por defecto', translatable: true, public: true),
            new SettingDefinition('seo', 'og_image', 'media', 'Imagen para redes sociales (OpenGraph)', translatable: false, public: true),
            new SettingDefinition('notifications', 'order_alert_email', 'email', 'Correo que recibe alertas de pedidos', translatable: false, public: false),
        ])->keyBy(fn (SettingDefinition $d) => $d->id())->all();
    }

    public static function get(string $group, string $key): SettingDefinition
    {
        return self::all()["{$group}.{$key}"] ?? throw new UnknownSetting($group, $key);
    }
}
