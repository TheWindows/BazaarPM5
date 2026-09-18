<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\plugin\Plugin;

class CustomItemParser {

    /**
     * Build the sellable Item for a config entry.
     * $key is the config key (e.g. "stone" or "god_sword").
     * $data is the entry array (legacy or extended).
     *
     * Extended format example:
     *   god_sword:
     *     item: "diamond_sword"
     *     buy: 5000
     *     sell: 425
     *     min_price: 4000
     *     max_price: 6000
     *     custom-name: "§cGod Sword"
     *     lore: ["§7Legendary weapon"]
     *     enchantments: {sharpness: 5, fire_aspect: 2}
     */
    public static function parse(string $key, array $data, ?Plugin $plugin = null): ?Item {
        $baseName = $key;
        foreach (["item", "id", "type", "base", "material"] as $alias) {
            if (isset($data[$alias]) && is_string($data[$alias]) && $data[$alias] !== "") {
                $baseName = $data[$alias];
                break;
            }
        }

        $item = StringToItemParser::getInstance()->parse($baseName);
        if ($item === null) {
            $plugin?->getLogger()->warning("Invalid base item '{$baseName}' for entry '{$key}', skipping.");
            return null;
        }

        $customName = self::stringField($data, ["custom-name", "custom_name", "name", "display-name", "display_name"]);
        if ($customName !== null && $customName !== "") {
            $item->setCustomName($customName);
        }

        $lore = self::loreField($data);
        if ($lore !== null) {
            $item->setLore($lore);
        }

        foreach (self::parseEnchantments($data, $plugin, $key) as $instance) {
            try {
                $item->addEnchantment($instance);
            } catch (\Throwable $e) {
                $plugin?->getLogger()->warning("Failed to apply enchantment for '{$key}': " . $e->getMessage());
            }
        }

        if (self::boolField($data, ["unbreakable"])) {
            if (method_exists($item, "setUnbreakable")) {
                $item->setUnbreakable(true);
            }
        }

        $item->setCount(1);
        return $item;
    }

    public static function isCustom(array $data): bool {
        return self::stringField($data, ["custom-name", "custom_name", "name", "display-name", "display_name"]) !== null
            || self::loreField($data) !== null
            || count(self::parseEnchantments($data)) > 0
            || self::boolField($data, ["unbreakable"])
            || isset($data["item"], $data["buy"]);
    }

    private static function stringField(array $data, array $aliases): ?string {
        foreach ($aliases as $alias) {
            if (isset($data[$alias]) && is_string($data[$alias])) {
                return $data[$alias];
            }
        }
        return null;
    }

    private static function boolField(array $data, array $aliases): bool {
        foreach ($aliases as $alias) {
            if (isset($data[$alias]) && $data[$alias]) {
                return true;
            }
        }
        return false;
    }

    /** @return string[]|null */
    private static function loreField(array $data): ?array {
        foreach (["lore", "description", "desc"] as $alias) {
            if (!isset($data[$alias])) {
                continue;
            }
            $raw = $data[$alias];
            if (is_string($raw)) {
                return [$raw];
            }
            if (is_array($raw)) {
                $lore = [];
                foreach ($raw as $line) {
                    if (is_string($line) || is_numeric($line)) {
                        $lore[] = (string) $line;
                    }
                }
                return $lore;
            }
        }
        return null;
    }

    /** @return EnchantmentInstance[] */
    private static function parseEnchantments(array $data, ?Plugin $plugin = null, string $key = ""): array {
        $raw = null;
        foreach (["enchantments", "enchant", "enchants"] as $alias) {
            if (isset($data[$alias]) && is_array($data[$alias])) {
                $raw = $data[$alias];
                break;
            }
        }
        if ($raw === null) {
            return [];
        }

        $instances = [];
        $parser = StringToEnchantmentParser::getInstance();

        if (self::isList($raw)) {
            // ["sharpness:5", "fire_aspect 2", "unbreaking"]
            foreach ($raw as $entry) {
                if (!is_string($entry) || trim($entry) === "") {
                    continue;
                }
                [$name, $level] = self::splitEnchantEntry($entry);
                $enchantment = $parser->parse(strtolower(trim($name)));
                if ($enchantment === null) {
                    $plugin?->getLogger()->warning("Unknown enchantment '{$name}'" . ($key !== "" ? " for '{$key}'" : "") . ", skipping.");
                    continue;
                }
                $instances[] = new EnchantmentInstance($enchantment, max(1, $level));
            }
        } else {
            // {sharpness: 5, fire_aspect: 2}
            foreach ($raw as $name => $level) {
                if (!is_string($name) || trim($name) === "") {
                    continue;
                }
                $enchantment = $parser->parse(strtolower(trim($name)));
                if ($enchantment === null) {
                    $plugin?->getLogger()->warning("Unknown enchantment '{$name}'" . ($key !== "" ? " for '{$key}'" : "") . ", skipping.");
                    continue;
                }
                $instances[] = new EnchantmentInstance($enchantment, max(1, (int) $level));
            }
        }

        return $instances;
    }

    /** @return array{0: string, 1: int} */
    private static function splitEnchantEntry(string $entry): array {
        $entry = trim($entry);
        foreach ([":", " ", "="] as $sep) {
            if (str_contains($entry, $sep)) {
                $parts = explode($sep, $entry, 2);
                return [trim($parts[0]), max(1, (int) trim($parts[1]))];
            }
        }
        return [$entry, 1];
    }

    private static function isList(array $arr): bool {
        $i = 0;
        foreach (array_keys($arr) as $k) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }
}
