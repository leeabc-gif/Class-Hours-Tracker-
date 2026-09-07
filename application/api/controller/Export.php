<?php
namespace app\api\controller;

use app\common\controller\Base;
use app\common\service\Backup;
use app\common\service\Export as ExportService;

/**
 * 导出 / 备份接口
 *
 * 文件名带中文，所以同时给 filename（兼容老浏览器）和
 * filename*=UTF-8''（RFC 5987，现代浏览器按这个取），
 * 否则 Chrome 下会拿到一串乱码文件名。
 */
class Export extends Base
{
    /**
     * 教师导出个人课时明细
     */
    public function mine()
    {
        $this->requireLogin();

        $scope = [];
        foreach (['term_id' => 'term_id', 'course_id' => 'course_id'] as $k => $f) {
            $v = intval($this->input($k, 0));
            if ($v) $scope[$f] = $v;
        }
        foreach (['month' => 'month', 'type' => 'type', 'keyword' => 'keyword'] as $k => $f) {
            $v = trim($this->input($k, ''));
            if ($v !== '') $scope[$f] = $v;
        }

        $data = ExportService::teacherRows($this->user->id, $scope);
        $name = $this->user->getData('name');

        $this->log('export', 'lesson', 0, "导出个人课时明细（{$name}，共 " . count($data['rows']) . " 条）");

        $this->download(
            "课时明细_{$name}_" . date('Ymd') . '.csv',
            ExportService::toCsv($data['header'], $data['rows'])
        );
    }

    /**
     * 管理员导出全校数据
     * type = summary（教师工资汇总）/ detail（课时明细）/ department（院系汇总）
     */
    public function school()
    {
        $this->requireAdmin();

        $type = trim($this->input('type', 'summary'));
        $termId = intval($this->input('term_id', 0));
        $deptId = intval($this->input('department_id', 0));

        if ($type === 'detail') {
            $scope = [];
            if ($termId) $scope['term_id'] = $termId;
            $tid = intval($this->input('teacher_id', 0));
            if ($tid) $scope['teacher_id'] = $tid;
            $data  = ExportService::schoolDetailRows($scope);
            $label = '课时明细';
        } elseif ($type === 'department') {
            $data  = ExportService::departmentRows($termId);
            $label = '院系汇总';
        } else {
            $data  = ExportService::schoolSummaryRows($termId, $deptId);
            $label = '课时工资汇总';
        }

        $this->log('export', 'lesson', 0, "导出全校{$label}（共 " . (count($data['rows']) - ($type === 'summary' ? 1 : 0)) . " 行）");

        $this->download(
            "全校{$label}_" . date('Ymd') . '.csv',
            ExportService::toCsv($data['header'], $data['rows'])
        );
    }

    /**
     * 管理员导出月度课酬对账表
     */
    public function reconcile()
    {
        $this->requireAdmin();

        $termId = intval($this->input('term_id', 0));
        $deptId = intval($this->input('department_id', 0));
        $data   = ExportService::reconcileRows($termId, $deptId);

        $this->log('export', 'lesson', 0, '导出月度课酬对账表（共 ' . count($data['rows']) . ' 行）');

        $this->download(
            '月度课酬对账_' . date('Ymd') . '.csv',
            ExportService::toCsv($data['header'], $data['rows'])
        );
    }

    /**
     * 管理员整库 SQL 备份
     */
    public function backup()
    {
        $this->requireAdmin();

        $result = Backup::dump();

        $this->log('backup', 'system', 0, sprintf('整库备份：%d 张表，%d 行数据，%.1f KB',
            $result['tables'], $result['rows'], $result['size'] / 1024));

        $this->download(
            'keshi_backup_' . date('Ymd_His') . '.sql',
            $result['sql']
        );
    }

    /**
     * 输出下载流
     */
    private function download($filename, $content)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename)
            . "\"; filename*=UTF-8''" . rawurlencode($filename));
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $content;
        exit;
    }
}
