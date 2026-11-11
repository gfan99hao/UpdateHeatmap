<?php
/**
 * 更新热力图 (Update Heatmap)
 *
 * @package UpdateHeatmap
 * @author  Gavin
 * @version 1.8.1
 * @since   1.0.0
 * @link    https://typecho.org
 */
namespace TypechoPlugin\UpdateHeatmap;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Plugin\PluginInterface;
use Typecho\Db;

class Plugin implements PluginInterface
{
    /** 色板定义（渐变锚点，从浅到深）：[空色, 中间色, 深色] */
    private static array $palettes = [
        'github'  => ['#ebedf0', '#40c463', '#14532d'],
        'blue'    => ['#e8f1fa', '#3b82f6', '#172554'],
        'purple'  => ['#f3eefa', '#a855f7', '#2e1065'],
        'orange'  => ['#fdf0e3', '#f97316', '#431407'],
        'pink'    => ['#fdeef4', '#ec4899', '#500724'],
        'mono'    => ['#eeeeee', '#777777', '#000000'],
    ];

    public static function activate()
    {
        // 文章发布/保存/状态变更/删除后清除热力图缓存，保证前台数据即时更新
        // 同时注册两种类名风格：1.3.0+/TypeRenew 用命名空间名，1.2.x 用下划线名
        $hooks = array_fill_keys(
            ['finishPublish', 'finishSave', 'finishMark', 'finishDelete'],
            [self::class, 'clearCache']
        );
        foreach (['Widget\Contents\Post\Edit', 'Widget_Contents_Post_Edit'] as $point) {
            $factory = \Typecho\Plugin::factory($point);
            foreach ($hooks as $name => $callback) {
                $factory->$name = $callback;
            }
        }

        return _t('UpdateHeatmap 已启用。本插件不会自动注入页面，请在主题模板需要的位置调用 Plugin::output() 手动插入。文章发布或修改后热力图数据会自动刷新。');
    }

    public static function deactivate() {}

    /**
     * 清除全部热力图数据缓存（挂载于 Widget\Contents\Post\Edit 的 finish* 钩子）
     * 缓存文件按"起始日期 + 站点根"散列命名，直接删除所有 uh_v2_*.cache 最可靠
     */
    public static function clearCache(...$args)
    {
        foreach (glob(sys_get_temp_dir() . '/uh_v2_*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** 同页只渲染一次，防止多处调用产生重复 DOM id 与样式 */
    private static bool $rendered = false;

    public static function config(\Typecho\Widget\Helper\Form $form)
    {
        $days = new \Typecho\Widget\Helper\Form\Element\Text(
            'days', null, '365', _t('显示时间范围（天）'), _t('热力图向今天追溯的天数，例如 180、365、730'));
        $days->addRule('isInteger', _t('请填写整数天数'));
        $form->addInput($days);

        $style = new \Typecho\Widget\Helper\Form\Element\Select(
            'style', [null => _t('请选择')] + array_combine(array_keys(self::$palettes),
            array_map('_t', ['GitHub 绿', '蓝色', '紫色', '橙色', '粉色', '黑白'])) + ['custom' => _t('自定义')],
            'github', _t('颜色风格'), _t('热力图色系'));
        $form->addInput($style);

        $customColors = new \Typecho\Widget\Helper\Form\Element\Text(
            'customColors', null, '#ebedf0,#9be9a8,#40c463,#30a14e,#216e39', _t('自定义颜色（风格选“自定义”时生效）'),
            _t('逗号分隔的 HEX 颜色，2-8 个；第一个为空档色，最后一个为最深色，中间按渐变插值。例：#ebedf0,#9be9a8,#40c463,#216e39。格式非法时自动回退 GitHub 绿'));
        $form->addInput($customColors);

        $levels = new \Typecho\Widget\Helper\Form\Element\Text(
            'levels', null, '4', _t('色阶数量'), _t('颜色分为几档（含空档），建议 3-5'));
        $levels->addRule('isInteger', _t('请填写整数'));
        $form->addInput($levels);

        $threshold = new \Typecho\Widget\Helper\Form\Element\Text(
            'threshold', null, '0', _t('每档阈值（0 为自动）'),
            _t('单日活动量（新建+修改）达到多少换一档颜色；填 0 则按最大值自动均分'));
        $threshold->addRule('isInteger', _t('请填写整数'));
        $form->addInput($threshold);

        $cache = new \Typecho\Widget\Helper\Form\Element\Text(
            'cacheTime', null, '3600', _t('数据缓存时长（秒，0 为禁用）'),
            _t('聚合查询结果缓存，热力图数据每天至多变化一次，建议保留缓存以减少数据库查询'));
        $cache->addRule('isInteger', _t('请填写整数'));
        $form->addInput($cache);

        $borderAlpha = new \Typecho\Widget\Helper\Form\Element\Text(
            'borderAlpha', null, '8', _t('格子描边浓度（0-100，0 为关闭描边）'),
            _t('色块底色描边的透明度百分比，浅色主题建议 8-15，数值越大描边越明显'));
        $borderAlpha->addRule('isInteger', _t('请填写整数'));
        $form->addInput($borderAlpha);

        $darkMode = new \Typecho\Widget\Helper\Form\Element\Select(
            'darkMode', ['0' => _t('浅色主题'), '1' => _t('深色主题'), 'auto' => _t('跟随系统（自动深浅切换）')],
            '0', _t('主题适配'), _t('“跟随系统”时通过 prefers-color-scheme 媒体查询自动切换描边与文字颜色'));
        $form->addInput($darkMode);

        $title = new \Typecho\Widget\Helper\Form\Element\Text(
            'title', null, '更新热力图（共 {total} 次活动）', _t('标题文字（留空则不显示）'),
            _t('{total} 会替换为活动总次数，例如：更新热力图（共 {total} 次活动）、我的写作记录'));
        $form->addInput($title);
    }

    public static function personalConfig(\Typecho\Widget\Helper\Form $form) {}

    /** 模板手动调用 */
    public static function output()
    {
        self::render();
    }

    /** 模板调用入口：调用即渲染（手动插入模式，调用本身就是启用信号） */
    public static function render()
    {
        if (self::$rendered) {
            return;
        }
        self::$rendered = true;

        // 配置读取失败（如插件被禁用后模板仍调用）时回退默认值，保证输出不消失
        try {
            $cfg = \Widget\Options::alloc()->plugin('UpdateHeatmap');
        } catch (\Throwable $e) {
            $cfg = null;
        }
        $cfg = $cfg ?: new \stdClass();
        $days      = min(3650, max(30, (int) ($cfg->days ?? 365)));
        $style     = (string) ($cfg->style ?? 'github');
        $levels    = min(6, max(2, (int) ($cfg->levels ?? 4)));
        $threshold = max(0, (int) ($cfg->threshold ?? 0));
        $cacheTime = max(0, (int) ($cfg->cacheTime ?? 3600));
        $alpha     = min(100, max(0, (int) ($cfg->borderAlpha ?? 8))) / 100;
        $mode      = (string) ($cfg->darkMode ?? '0');
        $mode      = in_array($mode, ['0', '1', 'auto'], true) ? $mode : '0';

        // 解析色板：自定义风格非法或未填时回退预设
        $anchors = [];
        if ('custom' === $style) {
            $anchors = self::parseColors((string) ($cfg->customColors ?? ''));
        }
        $anchors = $anchors ?: (self::$palettes[$style] ?? self::$palettes['github']);

        [$start, $end] = self::range($days);
        $data = self::fetchData($start, $end, $cacheTime);
        // title 键缺失（配置读取失败）回退默认标题；用户主动清空（存了空串）仍视为隐藏
        $title = trim((string) ($cfg->title ?? '更新热力图（共 {total} 次活动）'));

        echo self::html($start, $end, $data, $anchors, $levels, $threshold, $alpha, $mode, $title);
    }

    private static function range(int $days): array
    {
        $today = strtotime('today');
        // 含今天在内恰好 $days 个自然日
        return [strtotime('-' . ($days - 1) . ' days', $today), $today];
    }

    /**
     * 查询活动数据（单条查询 + PHP 分桶，避免 MySQL 时区与博客时区不一致）
     * @return array<string, array{n:int,m:int}> 'Y-m-d' => ['n'=>新建次数, 'm'=>修改次数]
     */
    private static function fetchData(int $start, int $end, int $cacheTime): array
    {
        $cacheFile = null;
        if ($cacheTime > 0) {
            // 文件名含数据格式版本号，升级数据结构后旧缓存自动成为孤儿文件
            $cacheFile = sys_get_temp_dir() . '/uh_v2_' . md5(__TYPECHO_ROOT_DIR__ . $start) . '.cache';
            $cached = self::readCache($cacheFile, $cacheTime);
            if ($cached !== null) {
                return $cached;
            }
        }

        $limit = $end + 86400; // 排他上界：含今天全天
        try {
            $db   = Db::get();
            $rows = $db->fetchAll(
                $db->select('created', 'modified')->from('table.contents')
                    ->where('type = ? AND status = ?', 'post', 'publish')
                    ->where('((created >= ? AND created < ?) OR (modified >= ? AND modified < ?))',
                        $start, $limit, $start, $limit)
            );
        } catch (\Throwable $e) {
            // 数据库异常时降级为空数据渲染，且不写缓存（避免把故障快照钉住整个缓存周期）
            return [];
        }

        $data = [];
        foreach ($rows as $r) {
            $created  = (int) $r['created'];
            $modified = (int) $r['modified'];
            // 新建：按 created 当日 +1
            if ($created >= $start && $created < $limit) {
                $day = date('Y-m-d', $created);
                $data[$day] = ['n' => ($data[$day]['n'] ?? 0) + 1, 'm' => $data[$day]['m'] ?? 0];
            }
            // 修改：modified 晚于 created 至少 1 小时视为一次修改
            //（Typecho 只记录最后一次修改时间，无法还原历史修改记录）
            if ($modified > $created + 3600 && $modified >= $start && $modified < $limit) {
                $day = date('Y-m-d', $modified);
                $data[$day] = ['n' => $data[$day]['n'] ?? 0, 'm' => ($data[$day]['m'] ?? 0) + 1];
            }
        }

        if ($cacheFile !== null) {
            self::writeCache($cacheFile, $data);
        }
        return $data;
    }

    /** @return array<string,array{n:int,m:int}>|null */
    private static function readCache(string $file, int $ttl): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || !isset($payload['t'], $payload['d']) || !is_array($payload['d'])) {
            return null;
        }
        if (time() - (int) $payload['t'] > $ttl) {
            return null;
        }
        // 严格校验：任一条目不符合 ['n'=>int,'m'=>int] 结构，整个缓存视为损坏重新查询
        //（旧版单值缓存、被篡改文件都在此被拒，避免把"全部无效"当成"有效空数据"命中）
        foreach ($payload['d'] as $k => $v) {
            if (
                !is_string($k) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $k)
                || !is_array($v) || !isset($v['n'], $v['m']) || !is_int($v['n']) || !is_int($v['m'])
            ) {
                return null;
            }
        }
        return $payload['d'];
    }

    private static function writeCache(string $file, array $data): void
    {
        // 随机后缀防临时文件名被预测（共享主机符号链接攻击面），失败时清理残留
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize(['t' => time(), 'd' => $data])) !== false) {
            if (!@rename($tmp, $file)) { // 原子替换，避免并发读写到半截文件
                @unlink($tmp);
            }
        }
        // 顺手清理滚动窗口产生的孤儿缓存（每天一个新文件，>2 天的已无价值）
        foreach (glob(dirname($file) . '/uh_v2_*.cache') ?: [] as $old) {
            if ($old !== $file && (@filemtime($old) ?: 0) < time() - 172800) {
                @unlink($old);
            }
        }
    }

    /**
     * 解析自定义颜色串，返回合法 HEX 数组；不足 2 个返回空数组（触发回退）
     * @return string[]
     */
    private static function parseColors(string $raw): array
    {
        $colors = [];
        foreach (explode(',', $raw) as $c) {
            $c = trim($c);
            if (preg_match('/^#([0-9a-fA-F]{3})$/', $c, $m)) {
                $c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
            }
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
                $colors[] = strtolower($c);
            }
        }
        return count($colors) >= 2 && count($colors) <= 8 ? $colors : [];
    }

    private static function pick(string $hexA, string $hexB, float $t): string
    {
        $a = sscanf($hexA, '#%02x%02x%02x');
        $b = sscanf($hexB, '#%02x%02x%02x');
        return sprintf('#%02x%02x%02x',
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t));
    }

    /**
     * 多锚点渐变：anchors[0] 为空档色，anchors[last] 为最深色，中间按段插值
     * @param string[] $anchors
     */
    private static function levelColors(array $anchors, int $levels): array
    {
        $last = count($anchors) - 1;
        $colors = [$anchors[0]];
        for ($i = 1; $i < $levels; $i++) {
            $t   = $i / ($levels - 1) * $last;
            $seg = (int) min($last - 1, (int) floor($t));
            $colors[] = self::pick($anchors[$seg], $anchors[$seg + 1], $t - $seg);
        }
        return $colors;
    }

    private static function html(int $start, int $end, array $data, array $anchors, int $levels, int $threshold,
                                 float $alpha, string $mode, string $title): string
    {
        $colors = self::levelColors($anchors, $levels);

        $max = 1;
        foreach ($data as $v) {
            $max = max($max, $v['n'] + $v['m']);
        }
        $step = $threshold > 0 ? $threshold : max(1, (int) ceil($max / ($levels - 1)));

        // 逐日步进：每天 1 次 strtotime + 1 次 getdate（同时取日期串与星期），避免夏令时漂移
        $firstWday = (int) date('w', $start);
        $cursor = strtotime('-' . $firstWday . ' days', $start);
        $cell   = '';
        $total  = 0;
        while ($cursor <= $end) {
            $cell .= '<span class="uh-col">';
            while (true) {
                $isSat = false;
                if ($cursor < $start) {
                    $cell .= '<i class="uh-cell uh-hide"></i>';
                } else {
                    $g   = getdate($cursor);
                    $day = $g['year'] . sprintf('-%02d-%02d', $g['mon'], $g['mday']);
                    $n   = $data[$day]['n'] ?? 0;
                    $m   = $data[$day]['m'] ?? 0;
                    $act = $n + $m;
                    $total += $act;
                    $lv = $act <= 0 ? 0 : min($levels - 1, (int) (($act + $step - 1) / $step));
                    $parts = [];
                    if ($n > 0) $parts[] = '新建 ' . $n . ' 次';
                    if ($m > 0) $parts[] = '修改 ' . $m . ' 次';
                    $tip   = htmlspecialchars($day . '｜' . ($parts ? implode(' · ', $parts) : '暂无活动'), ENT_QUOTES, 'UTF-8');
                    $cell .= '<i class="uh-cell uh-lv' . $lv . '" data-tip="' . $tip . '"></i>';
                    $isSat = 6 === $g['wday'];
                }
                $cursor = strtotime('+1 day', $cursor);
                if ($isSat) break;
                if ($cursor > $end) {
                    // 补齐最后一列尾部的隐藏格，保证每周都是 7 格
                    for ($p = (int) date('w', $end); $p < 6; $p++) {
                        $cell .= '<i class="uh-cell uh-hide"></i>';
                    }
                    break;
                }
            }
            $cell .= '</span>';
        }

        $legend = '';
        for ($i = 0; $i < $levels; $i++) {
            $label  = $i === 0 ? '0' : ($step * $i . '+');
            $legend .= '<span class="uh-legend-item"><i class="uh-lv' . $i . '"></i>' . $label . '</span>';
        }

        // 主题：CSS 变量按模式生成；auto 模式用 prefers-color-scheme 媒体查询覆盖
        // number_format 不受 locale 影响（%f 在部分 locale 下会输出逗号小数，破坏 CSS）
        $a = number_format($alpha, 3, '.', '');
        $border = $alpha > 0 ? 'rgba(0,0,0,' . $a . ')' : 'transparent';
        $varsLight = '--uh-border:' . $border . ';--uh-border-hover:rgba(0,0,0,.25);--uh-shadow:rgba(0,0,0,.3)';
        $varsDark  = '--uh-border:' . ($alpha > 0 ? 'rgba(255,255,255,' . $a . ')' : 'transparent')
            . ';--uh-border-hover:rgba(255,255,255,.45);--uh-shadow:rgba(0,0,0,.6)';

        $uid = 'uh' . substr(md5(implode('', $anchors) . $levels . $threshold . $alpha . $mode . $title), 0, 6);
        // 色阶集中为类定义，格子不再携带内联颜色，HTML 体积更小
        $lvCss = '';
        foreach ($colors as $i => $c) {
            $lvCss .= '#' . $uid . ' .uh-lv' . $i . '{background:' . $c . '}';
        }
        $titleHtml = '';
        if ($title !== '') {
            $titleText = strtr($title, ['{total}' => (string) $total]);
            $titleHtml = '<div class="uh-title">' . htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if ('1' === $mode) {
            $themeCss = '#' . $uid . '{' . $varsDark . '}';
        } elseif ('auto' === $mode) {
            $themeCss = '#' . $uid . '{' . $varsLight . '}@media(prefers-color-scheme:dark){#' . $uid . '{' . $varsDark . '}}';
        } else {
            $themeCss = '#' . $uid . '{' . $varsLight . '}';
        }

        return <<<HTML
<div id="{$uid}" class="uh-wrap">
  {$titleHtml}
  <div class="uh-grid">{$cell}</div>
  <div class="uh-footer"><span>少</span><span class="uh-legend">{$legend}</span><span>多</span></div>
  <div class="uh-tip" role="tooltip"></div>
</div>
<script>
(function(){
  var wrap=document.getElementById('{$uid}');
  if(!wrap)return;
  var tip=wrap.querySelector('.uh-tip');
  var grid=wrap.querySelector('.uh-grid');
  if(!tip||!grid)return;
  grid.addEventListener('mouseover',function(e){
    var t=e.target.closest?e.target.closest('.uh-cell[data-tip]'):null;
    if(!t)return;
    tip.textContent=t.getAttribute('data-tip');
    tip.classList.add('uh-on');
  });
  grid.addEventListener('mousemove',function(e){
    if(!tip.classList.contains('uh-on'))return;
    var w=tip.offsetWidth,h=tip.offsetHeight;
    var x=e.clientX-w/2,y=e.clientY-h-12;
    if(y<8)y=e.clientY+16;
    x=Math.max(8,Math.min(x,window.innerWidth-w-8));
    tip.style.left=x+'px';tip.style.top=y+'px';
  });
  grid.addEventListener('mouseleave',function(){
    tip.classList.remove('uh-on');
  });
})();
</script>
<style>
@keyframes uh-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
{$themeCss}
#{$uid}{margin:24px 0;width:100%;font:12px/1.5 sans-serif;color:inherit;animation:uh-in .5s ease}
#{$uid} .uh-title{margin-bottom:8px;font-weight:600;color:inherit;opacity:.85}
#{$uid} .uh-grid{display:flex;gap:3px;overflow-x:auto;padding:2px 4px 6px}
#{$uid} .uh-col{flex:1 1 0;min-width:3px;display:flex;flex-direction:column;gap:3px}
#{$uid} .uh-cell{width:100%;aspect-ratio:1/1;border-radius:2px;display:block;cursor:pointer;box-sizing:border-box;border:1px solid var(--uh-border);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
{$lvCss}
#{$uid} .uh-cell:hover{transform:scale(1.3);box-shadow:0 0 5px var(--uh-shadow);border-color:var(--uh-border-hover);position:relative;z-index:2}
#{$uid} .uh-hide{visibility:hidden}
#{$uid} .uh-footer{display:flex;align-items:center;gap:6px;margin-top:8px}
#{$uid} .uh-legend{display:inline-flex;align-items:center;gap:3px}
#{$uid} .uh-legend-item{display:inline-flex;align-items:center;gap:2px;transition:transform .15s ease}
#{$uid} .uh-legend-item:hover{transform:scale(1.15)}
#{$uid} .uh-legend-item i{width:11px;height:11px;border-radius:2px;display:inline-block;box-sizing:border-box;border:1px solid var(--uh-border)}
#{$uid} .uh-tip{position:fixed;left:0;top:0;z-index:9999;max-width:260px;padding:6px 10px;border-radius:6px;background:#1f2937;color:#fff;font-size:12px;line-height:1.5;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.25);pointer-events:none;opacity:0;visibility:hidden;transform:translateY(2px);transition:opacity .15s ease,transform .15s ease}
#{$uid} .uh-tip.uh-on{opacity:1;visibility:visible;transform:translateY(0)}
@media (prefers-reduced-motion:reduce){#{$uid},#{$uid} .uh-cell,#{$uid} .uh-legend-item,#{$uid} .uh-tip{animation:none;transition:none}}
</style>
HTML;
    }
}
