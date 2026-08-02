<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\traits\ApiResponse;
use app\common\model\Task;
use app\common\model\UserAccount;
use app\common\service\JwtService;
use think\facade\Config;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Filesystem;
use think\File;
use think\Response;

/**
 * 加固任务控制器(需登录)
 */
class TaskController extends BaseController
{
    use ApiResponse;

    // 允许的文件扩展名
    private array $allowedExtensions = ['php', 'inc', 'java', 'jar', 'js', 'mjs', 'py', 'cpp', 'cc', 'cxx', 'c', 'h', 'hpp', 'zip', 'tar', 'gz'];

    /**
     * 创建加固任务
     * POST /api/task/create
     */
    public function create()
    {
        $uid = $this->userId();
        if ($uid <= 0) {
            return $this->fail('请先登录', 2001, null, 401);
        }

        $language = strtolower(trim((string) $this->request->post('language', '')));
        $allowedLangs = Config::get('hardening.languages', ['php', 'java', 'javascript', 'python', 'cpp']);
        if (!in_array($language, $allowedLangs, true)) {
            return $this->fail('不支持的语言,支持: ' . implode(',', $allowedLangs), 2011);
        }

        // 文件校验
        $file = $this->request->file('file');
        if (!$file) {
            return $this->fail('请上传待加固文件', 2012);
        }
        /** @var File $file */
        $maxSize = (int) Config::get('hardening.max_file_size', 10485760);
        if ($file->getSize() <= 0) {
            return $this->fail('文件为空', 2013);
        }
        if ($file->getSize() > $maxSize) {
            return $this->fail('文件大小超过限制(' . round($maxSize / 1024 / 1024, 1) . 'MB)', 2014);
        }
        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, $this->allowedExtensions, true)) {
            return $this->fail('不支持的文件类型: ' . $ext, 2015);
        }

        // 解析加固选项
        $options = $this->parseOptions((string) $this->request->post('options', ''));

        // 配额校验
        $account = UserAccount::where('user_id', $uid)->find();
        if (!$account) {
            return $this->fail('账户不存在', 2016);
        }
        if ($account->isPlanExpired()) {
            return $this->fail('套餐已过期,请续费', 2017);
        }

        // 保存文件
        $saveName = Filesystem::disk('local')->putFile('source', $file);
        $sourceFile = $saveName;

        Db::startTrans();
        try {
            // 原子性配额扣减（防止并发超限）
            if (!$account->tryIncrementUsage()) {
                Db::rollback();
                return $this->fail('今日加固次数已达上限(' . $account->daily_quota . ')', 2018);
            }

            $task = Task::create([
                'user_id'     => $uid,
                'task_no'     => Task::generateTaskNo(),
                'language'    => $language,
                'options'     => $options,
                'source_file' => $sourceFile,
                'result_file' => '',
                'status'      => Task::STATUS_PENDING,
                'progress'    => 0,
                'error_msg'   => '',
                'file_size'   => $file->getSize(),
                'duration'    => 0,
            ]);
            // 推入队列(此处用缓存模拟队列)
            Cache::store('redis')->push('queue:hardening', json_encode(['task_id' => $task->id]));
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('创建任务失败: ' . $e->getMessage(), 2019);
        }

        return $this->success([
            'task_id'  => (int) $task->id,
            'task_no'  => $task->task_no,
            'status'   => $task->status,
            'progress' => 0,
        ], '任务已创建,正在排队处理');
    }

    /**
     * 查询任务状态
     * GET /api/task/status/:id
     */
    public function status($id)
    {
        $uid = $this->userId();
        $task = $this->getUserTask((int) $id, $uid);
        if (!$task) {
            return $this->fail('任务不存在', 2021, null, 404);
        }
        return $this->success([
            'task_id'   => (int) $task->id,
            'task_no'   => $task->task_no,
            'status'    => $task->status,
            'progress'  => (int) $task->progress,
            'language'  => $task->language,
            'error_msg' => $task->error_msg,
        ]);
    }

    /**
     * 查询加固结果
     * GET /api/task/result/:id
     */
    public function result($id)
    {
        $uid = $this->userId();
        $task = $this->getUserTask((int) $id, $uid);
        if (!$task) {
            return $this->fail('任务不存在', 2021, null, 404);
        }
        if ($task->status !== Task::STATUS_COMPLETED) {
            return $this->fail('任务尚未完成,当前状态: ' . $task->status, 2022);
        }
        $downloadUrl = $this->request->domain() . '/api/task/download/' . $task->id;
        return $this->success([
            'task_id'      => (int) $task->id,
            'task_no'      => $task->task_no,
            'status'       => $task->status,
            'language'     => $task->language,
            'options'      => $task->options,
            'file_size'    => (int) $task->file_size,
            'duration'     => (int) $task->duration,
            'created_at'   => $task->created_at,
            'completed_at' => $task->completed_at,
            'download_url' => $downloadUrl,
        ]);
    }

    /**
     * 下载加固后的文件
     * GET /api/task/download/:id
     */
    public function download($id)
    {
        $uid = $this->userId();
        $task = $this->getUserTask((int) $id, $uid);
        if (!$task) {
            return $this->fail('任务不存在', 2021, null, 404);
        }
        if ($task->status !== Task::STATUS_COMPLETED) {
            return $this->fail('任务尚未完成', 2022);
        }
        $resultFile = (string) $task->result_file;
        if ($resultFile === '') {
            return $this->fail('结果文件不存在', 2023);
        }
        $fullPath = Filesystem::disk('local')->path($resultFile);
        if (!is_file($fullPath)) {
            return $this->fail('结果文件已丢失', 2024);
        }
        $filename = basename($fullPath);
        return download($fullPath, $filename)->force(true);
    }

    /**
     * 任务历史列表
     * GET /api/task/history
     */
    public function history()
    {
        $uid = $this->userId();
        [$page, $size] = $this->pageParams();
        $status = $this->request->param('status', '');
        $language = $this->request->param('language', '');

        $query = Task::where('user_id', $uid);
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($language !== '') {
            $query->where('language', strtolower($language));
        }
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();

        return $this->success([
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'page_size' => $size,
        ]);
    }

    /**
     * 删除任务
     * DELETE /api/task/:id
     */
    public function delete($id)
    {
        $uid = $this->userId();
        $task = $this->getUserTask((int) $id, $uid);
        if (!$task) {
            return $this->fail('任务不存在', 2021, null, 404);
        }
        // 删除关联文件
        if (!empty($task->source_file)) {
            $this->removeFile((string) $task->source_file);
        }
        if (!empty($task->result_file)) {
            $this->removeFile((string) $task->result_file);
        }
        $task->delete();
        return $this->success(null, '任务已删除');
    }

    /**
     * 获取当前用户任务
     */
    private function getUserTask(int $id, int $uid): ?Task
    {
        if ($id <= 0 || $uid <= 0) {
            return null;
        }
        return Task::where('id', $id)->where('user_id', $uid)->find();
    }

    /**
     * 解析加固选项
     */
    private function parseOptions(string $raw): array
    {
        $defaults = Config::get('hardening.default_options', [
            'obfuscate_level'  => 2,
            'encrypt_strings'  => true,
            'remove_comments'  => true,
            'control_flow'     => false,
        ]);
        if ($raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $options = $defaults;
        // 加固级别 1-3
        if (isset($decoded['obfuscate_level'])) {
            $level = (int) $decoded['obfuscate_level'];
            $options['obfuscate_level'] = max(1, min(3, $level));
        }
        if (isset($decoded['encrypt_strings'])) {
            $options['encrypt_strings'] = (bool) $decoded['encrypt_strings'];
        }
        if (isset($decoded['remove_comments'])) {
            $options['remove_comments'] = (bool) $decoded['remove_comments'];
        }
        if (isset($decoded['control_flow'])) {
            $options['control_flow'] = (bool) $decoded['control_flow'];
        }
        return $options;
    }

    /**
     * 删除磁盘文件
     */
    private function removeFile(string $path): void
    {
        try {
            $full = Filesystem::disk('local')->path($path);
            if (is_file($full)) {
                @unlink($full);
            }
        } catch (\Throwable $e) {
            // 忽略文件删除异常
        }
    }
}
