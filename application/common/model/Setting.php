<?php
namespace app\common\model;

use think\Model;
use think\Db;

/**
 * 系统配置
 */
class Setting extends Model
{
    protected $table    = 'ks_setting';
    protected $pk       = 'id';

    /** 内存缓存，避免同一次请求反复查库 */
    protected static $cache = null;

    /**
     * 读取配置值
     */
    public static function get($key, $default = '')
    {
        $all = self::all();
        return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
    }

    /**
     * 写入配置值
     */
    public static function set($key, $value)
    {
        $row = self::where('cfg_key', $key)->find();
        if ($row) {
            $row->cfg_value = $value;
            $row->save();
        } else {
            self::create(['cfg_key' => $key, 'cfg_value' => $value]);
        }
        self::$cache = null;
    }

    /**
     * 一次性取全部配置为关联数组
     */
    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (self::select() as $row) {
                self::$cache[$row->cfg_key] = $row->cfg_value;
            }
        }
        return self::$cache;
    }

    /**
     * 批量写入（只更新传入的键）
     */
    public static function batchSet($pairs)
    {
        foreach ($pairs as $k => $v) {
            self::set($k, $v);
        }
        self::$cache = null;
    }
}
