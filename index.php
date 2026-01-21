<?php
define('QUESTION_FILE', './survey_questions.txt');
define('ANSWER_FILE', './survey_answers.txt');
define('TITLE_FILE', './survey_title.txt');
define('LIMIT_TIME_FILE', './submit_limit_hours.txt');
define('LIMIT_SWITCH_FILE', './limit_switch.txt'); // 新增：提交限制开关配置文件
define('ADMIN_PASS_FILE', './admin_password.txt');

// 初始化所有文件（含开关文件，默认开启限制）
function initFiles() {
    $files = [QUESTION_FILE, ANSWER_FILE, TITLE_FILE, LIMIT_TIME_FILE, ADMIN_PASS_FILE, LIMIT_SWITCH_FILE];
    foreach ($files as $file) {
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!file_exists($file)) {
            $fp = fopen($file, 'w');
            if ($file === LIMIT_TIME_FILE) fwrite($fp, '24');
            if ($file === LIMIT_SWITCH_FILE) fwrite($fp, 'on'); // 默认开启限制
            fclose($fp);
            chmod($file, 0644);
        }
    }
}

// 获取/修改管理员密码
function getAdminPass() {
    initFiles();
    $pass = trim(file_get_contents(ADMIN_PASS_FILE));
    return !empty($pass) ? $pass : '123456';
}
function updateAdminPass($oldPass, $newPass1, $newPass2) {
    initFiles();
    $currentPass = getAdminPass();
    if ($oldPass !== $currentPass) return false;
    $newPass1 = trim($newPass1);
    $newPass2 = trim($newPass2);
    if (strlen($newPass1) < 6 || strlen($newPass1) > 16 || $newPass1 !== $newPass2) return false;
    file_put_contents(ADMIN_PASS_FILE, $newPass1, LOCK_EX);
    return true;
}

// 获取/保存系统标题
function getSysTitle() {initFiles(); $title = trim(file_get_contents(TITLE_FILE)); return $title ?: "员工幸福感指数月度调研系统";}
function saveSysTitle($title) {initFiles(); file_put_contents(TITLE_FILE, trim($title), LOCK_EX); return true;}

// 新增：提交限制开关（on/off）
function getLimitSwitch() {
    initFiles();
    $switch = trim(file_get_contents(LIMIT_SWITCH_FILE));
    return in_array($switch, ['on', 'off']) ? $switch : 'on';
}
function saveLimitSwitch($switch) {
    initFiles();
    $switch = trim($switch);
    if (!in_array($switch, ['on', 'off'])) return false;
    file_put_contents(LIMIT_SWITCH_FILE, $switch, LOCK_EX);
    return true;
}

// 获取/保存提交限制时间（小时）
function getSubmitLimitHours() {
    initFiles();
    $hours = trim(file_get_contents(LIMIT_TIME_FILE));
    return is_numeric($hours) && (int)$hours > 0 ? (int)$hours : 24;
}
function saveSubmitLimitHours($hours) {
    initFiles();
    $hours = (int)$hours;
    if ($hours < 1) return false;
    file_put_contents(LIMIT_TIME_FILE, (string)$hours, LOCK_EX);
    return true;
}

// 调研问题管理
function getQuestions() {
    initFiles();
    $content = str_replace(["\r\n","\r"],"\n",file_get_contents(QUESTION_FILE));
    $lines = explode("\n",trim($content));
    return array_filter($lines,function($line){return !empty(trim($line));});
}
function addQuestion($question) {$question=trim($question); $qs=getQuestions(); if(empty($question)||in_array($question,$qs)) return false; file_put_contents(QUESTION_FILE, $question."\n", FILE_APPEND|LOCK_EX); return true;}
function deleteQuestion($index) {$qs=array_values(getQuestions()); if(!isset($qs[$index])) return false; unset($qs[$index]); file_put_contents(QUESTION_FILE, implode("\n",$qs), LOCK_EX); return true;}
function editQuestion($index, $newContent) {$newContent=trim($newContent); $qs=array_values(getQuestions()); if(!isset($qs[$index])||empty($newContent)) return false; $qs[$index]=$newContent; file_put_contents(QUESTION_FILE, implode("\n",$qs), LOCK_EX); return true;}
function sortQuestion($index, $direction) {
    $qs=array_values(getQuestions());
    if(!isset($qs[$index])) return false;
    $swapIdx = $direction=='up' ? $index-1 : $index+1;
    if(!isset($qs[$swapIdx])) return false;
    list($qs[$index],$qs[$swapIdx]) = [$qs[$swapIdx],$qs[$index]];
    file_put_contents(QUESTION_FILE, implode("\n",$qs), LOCK_EX);
    return true;
}

// 清空调研数据
function clearSurveyData() {
    initFiles();
    $fp = fopen(ANSWER_FILE, 'w');
    fclose($fp);
    chmod(ANSWER_FILE, 0644);
    return true;
}

// 核心修改：提交限制校验（浏览器指纹+本地存储，替代IP限制）
// 前端生成浏览器标识，后端记录提交时间，开关关闭时直接放行
function canSubmit($browserId, $submitTime) {
    $switch = getLimitSwitch();
    if ($switch === 'off') return true; // 开关关闭，无限制
    
    $limitHours = getSubmitLimitHours();
    $limitTime = strtotime("-{$limitHours} hours");
    
    if(!file_exists(ANSWER_FILE)) return true;
    $content = str_replace(["\r\n","\r"],"\n",file_get_contents(ANSWER_FILE));
    $lines = explode("\n",trim($content));
    
    foreach($lines as $line){
        // 匹配浏览器标识和提交时间
        if(strpos($line,"BROWSER_ID:{$browserId}")!==false && strpos($line,"SUBMIT_TIME:")!==false){
            $timeStr = trim(preg_replace('/.*SUBMIT_TIME:(\S+) BROWSER_ID:.*/','$1',$line));
            if(strtotime($timeStr)>=$limitTime) return false;
        }
    }
    return true;
}

// 提交打分（新增记录浏览器标识）
function submitAnswer($name, $scores, $feedbacks, $browserId) {
    initFiles(); $qs=getQuestions();
    if(empty($qs)||empty($scores)) return false;
    $submitData = [];
    $time = date('Y-m-d H:i:s');
    $name = trim($name);
    // 记录浏览器标识，替代IP
    $submitData[] = "===== SURVEY_RECORD_START NAME:{$name} SUBMIT_TIME:{$time} BROWSER_ID:{$browserId} =====";
    foreach($scores as $qIdx=>$score){
        $score=(int)$score; $feedback=isset($feedbacks[$qIdx])?trim($feedbacks[$qIdx]):'';
        if(isset($qs[$qIdx])){$submitData[]="Q:{$qs[$qIdx]}";$submitData[]="S:{$score}";$submitData[]="F:{$feedback}";}
    }
    $submitData[] = "===== SURVEY_RECORD_END ====="; $submitData[] = "";
    file_put_contents(ANSWER_FILE, implode("\n",$submitData), FILE_APPEND|LOCK_EX);
    return true;
}

// 处理POST请求
$msg='';$msgType='info'; $sysTitle=getSysTitle(); $questions=array_values(getQuestions());
$submitLimitHours = getSubmitLimitHours();
$limitSwitch = getLimitSwitch(); // 当前限制开关状态
$adminCurrentPass = getAdminPass();

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
    switch($_POST['action']){
        case 'save_title':
            if($_POST['admin_pass']===$adminCurrentPass && !empty(trim($_POST['sys_title']))){
                saveSysTitle($_POST['sys_title']);$msg="系统标题修改成功！";$msgType='success';
            }else{$msg="标题不能为空或密码错误！";$msgType='error';} break;
        case 'add_question':
            if($_POST['admin_pass']===$adminCurrentPass && addQuestion($_POST['question'])){$msg="问题添加成功！";$msgType='success';}
            else{$msg="问题为空/重复或密码错误！";$msgType='error';} break;
        case 'delete_question':
            if($_POST['admin_pass']===$adminCurrentPass && deleteQuestion((int)$_POST['q_index'])){$msg="问题删除成功！";$msgType='success';}
            else{$msg="问题不存在或密码错误！";$msgType='error';} break;
        case 'edit_question':
            if($_POST['admin_pass']===$adminCurrentPass && editQuestion((int)$_POST['q_index'],$_POST['new_content'])){$msg="问题编辑成功！";$msgType='success';}
            else{$msg="问题不能为空或密码错误！";$msgType='error';} break;
        case 'sort_question':
            if($_POST['admin_pass']===$adminCurrentPass && sortQuestion((int)$_POST['q_index'],$_POST['direction'])){$msg="问题排序成功！";$msgType='success';}
            else{$msg="排序失败！已到顶/底或密码错误";$msgType='error';} break;
        case 'save_limit_hours':
            if($_POST['admin_pass']===$adminCurrentPass && saveSubmitLimitHours((int)$_POST['limit_hours'])){
                $submitLimitHours = getSubmitLimitHours();
                $msg="提交限制时间修改成功！当前限制：{$submitLimitHours}小时内仅可提交1次";
                $msgType='success';
            }else{
                $msg="限制时间必须为大于0的数字或密码错误！";$msgType='error';
            } break;
        // 新增：切换提交限制开关
        case 'toggle_limit_switch':
            if($_POST['admin_pass']===$adminCurrentPass){
                $newSwitch = $_POST['switch_state'];
                if(saveLimitSwitch($newSwitch)){
                    $limitSwitch = $newSwitch;
                    $msg = $newSwitch === 'on' ? "提交限制已开启！" : "提交限制已关闭，可无限制提交！";
                    $msgType='success';
                }else{$msg="开关切换失败！";$msgType='error';}
            }else{$msg="密码错误！";$msgType='error';} break;
        case 'clear_survey_data':
            if($_POST['admin_pass']===$adminCurrentPass && clearSurveyData()){
                $msg="调研数据清空成功！所有提交记录已删除，配置信息保留。";
                $msgType='success';
            }else{
                $msg="数据清空失败！密码错误或文件权限不足。";$msgType='error';
            } break;
        case 'update_admin_pass':
            $oldPass = $_POST['old_pass'] ?? '';
            $newPass1 = $_POST['new_pass1'] ?? '';
            $newPass2 = $_POST['new_pass2'] ?? '';
            $updateResult = updateAdminPass($oldPass, $newPass1, $newPass2);
            if ($updateResult) {
                $msg="管理员密码修改成功！请使用新密码登录操作，原密码失效。";
                $msgType='success';
            } else {
                $msg="密码修改失败！原密码错误、新密码长度不符（6-16位）或两次输入不一致。";
                $msgType='error';
            } break;
        case 'submit_answer':
            // 前端生成的浏览器标识和提交时间
            $browserId = $_POST['browser_id'] ?? '';
            $submitTime = date('Y-m-d H:i:s');
            
            if(!canSubmit($browserId, $submitTime)){
                $msg="{$submitLimitHours}小时内仅可提交1次，请勿重复提交！";
                $msgType='error';break;
            }
            $name = $_POST['user_name'] ?? '';
            $scores=$_POST['scores']??[];$feedbacks=$_POST['feedbacks']??[];$valid=true;
            foreach($scores as $s){$s=(int)$s;if($s<1||$s>10){$valid=false;break;}}
            if(!$valid||empty($scores)){$msg="每题必填1-10分！";$msgType='error';}
            elseif(submitAnswer($name, $scores, $feedbacks, $browserId)){
                $msg="提交成功！感谢反馈～";
                $msgType='success';
                // 提交成功后，前端localStorage记录状态（通过JS实现）
            }
            else{$msg="提交失败！";$msgType='error';} break;
    }
    $sysTitle=getSysTitle(); $questions=array_values(getQuestions());
    $limitSwitch = getLimitSwitch(); // 刷新开关状态
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?php echo $sysTitle;?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:"Microsoft YaHei";}
        .container{max-width:1200px;margin:30px auto;padding:0 20px;}
        .header{text-align:center;margin-bottom:30px;padding-bottom:20px;border-bottom:2px solid #2f96ff;}
        .limit-status{font-size:14px;color:#fff;padding:4px 12px;border-radius:20px;display:inline-block;margin-left:15px;}
        .status-on{background:#52c41a;}
        .status-off{background:#ff4d4f;}
        .card{background:#fff;border-radius:8px;box-shadow:0 2px 15px rgba(0,0,0,0.08);padding:30px;margin-bottom:30px;}
        .card-title{font-size:20px;margin-bottom:25px;border-left:5px solid #2f96ff;padding-left:15px;}
        .form-group{margin-bottom:25px;padding-bottom:20px;border-bottom:1px dashed #eee;}
        .form-label{font-size:16px;margin-bottom:12px;font-weight:500;}
        .score-select{width:120px;padding:10px;border:1px solid #ddd;border-radius:4px;margin-right:15px;}
        .feedback-input{width:calc(100% - 140px);padding:10px;border:1px solid #ddd;border-radius:4px;}
        .name-input{width:300px;padding:10px;border:1px solid #ddd;border-radius:4px;margin-bottom:15px;}
        .btn{padding:8px 16px;border:none;border-radius:4px;cursor:pointer;font-size:14px;}
        .btn-primary{background:#2f96ff;color:#fff;}
        .btn-danger{background:#ff4d4f;color:#fff;}
        .btn-sm{padding:4px 10px;font-size:12px;}
        .msg{padding:15px;border-radius:4px;margin-bottom:20px;text-align:center;}
        .msg-success{background:#f0fff4;color:#52c41a;border:1px solid #b7eb8f;}
        .msg-error{background:#fff2f0;color:#ff4d4f;border:1px solid #ffccc7;}
        .question-item{display:flex;justify-content:space-between;align-items:center;padding:12px;background:#fafafa;border-radius:4px;margin-bottom:10px;gap:10px;}
        .split{margin:40px 0;height:1px;background:#eee;}
        .score-tip{color:#999;font-size:14px;margin-top:5px;}
        .admin-sub-card{margin-bottom:30px;padding-bottom:25px;border-bottom:1px dashed #eee;}
        .edit-input{padding:6px 8px;border:1px solid #ddd;border-radius:4px;width:300px;}
        .name-tip{color:#999;font-size:13px;margin-top:-10px;margin-bottom:15px;}
        .limit-input{width:100px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;text-align:center;}
        .limit-tip{color:#999;font-size:13px;margin-top:8px;}
        .clear-tip{color:#ff4d4f;font-size:12px;margin-top:8px;font-weight:500;}
        .pass-input{width:300px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;margin-bottom:10px;}
        .pass-tip{color:#999;font-size:12px;margin-top:8px;line-height:1.5;}
        .pass-warning{color:#ff4d4f;font-size:11px;margin-top:5px;}
        .switch-group{display:flex;align-items:center;gap:10px;margin-top:10px;}
        .switch-label{font-size:14px;}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo htmlspecialchars($sysTitle);?></h1>
            <p>1-10分强制打分 | 可选实名 
                <?php if($limitSwitch === 'on'):?>
                    <span class="limit-status status-on">限制开启：<?php echo $submitLimitHours;?>小时内仅可提交1次</span>
                <?php else:?>
                    <span class="limit-status status-off">限制关闭：可无限制提交</span>
                <?php endif;?>
            </p>
        </div>
        <?php if(!empty($msg)):?><div class="msg msg-<?php echo $msgType;?>"><?php echo $msg;?></div><?php endif;?>

        <!-- 员工评价区 -->
        <div class="card">
            <div class="card-title">员工评价区</div>
            <?php if(!empty($questions)):?>
            <form method="post" action="" id="surveyForm">
                <input type="hidden" name="action" value="submit_answer">
                <!-- 隐藏域：存储浏览器标识 -->
                <input type="hidden" name="browser_id" id="browserId">
                <input type="text" class="name-input" name="user_name" placeholder="请输入您的姓名（选填，填写则为实名评价）">
                <div class="name-tip">不填写姓名则默认匿名提交，所有评价内容严格保密</div>
                
                <?php foreach($questions as $index=>$question):?>
                <div class="form-group">
                    <label class="form-label"><?php echo ($index+1).'. '.htmlspecialchars($question);?></label>
                    <select class="score-select" name="scores[<?php echo $index;?>]" required>
                        <option value="">请打分</option>
                        <option value="1">1分（非常不满意）</option><option value="2">2分</option><option value="3">3分</option><option value="4">4分</option><option value="5">5分（一般）</option>
                        <option value="6">6分</option><option value="7">7分</option><option value="8">8分</option><option value="9">9分</option><option value="10">10分（非常满意）</option>
                    </select>
                    <input type="text" class="feedback-input" name="feedbacks[<?php echo $index;?>]" placeholder="可选：补充意见或建议">
                    <div class="score-tip">打分必填，反馈选填</div>
                </div>
                <?php endforeach;?>
                <button type="submit" class="btn btn-primary">提交评价</button>
            </form>
            <?php else:?><p style="color:#999;text-align:center;padding:30px 0;">暂无调研问题，请等待管理员添加</p><?php endif;?>
        </div>

        <div class="split"></div>

        <!-- 管理员操作区 -->
        <div class="card">
            <div class="card-title">管理员操作区（当前密码：<?php echo $adminCurrentPass;?>）</div>
            <!-- 1. 修改管理员密码 -->
            <div class="admin-sub-card">
                <h4>1. 修改管理员密码</h4>
                <form method="post" action="">
                    <input type="hidden" name="action" value="update_admin_pass">
                    <div>
                        <label>原管理员密码：</label>
                        <input type="password" name="old_pass" class="pass-input" required placeholder="输入当前管理员密码">
                    </div>
                    <div>
                        <label>新管理员密码：</label>
                        <input type="password" name="new_pass1" class="pass-input" required placeholder="输入6-16位新密码">
                    </div>
                    <div>
                        <label>确认新管理员密码：</label>
                        <input type="password" name="new_pass2" class="pass-input" required placeholder="再次输入新密码">
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:5px;">保存新密码</button>
                    <div class="pass-tip">
                        提示：1. 新密码长度限制6-16位；2. 修改后立即生效，请妥善保管；3. 忘记密码可删除admin_password.txt文件恢复初始密码123456
                    </div>
                    <div class="pass-warning">⚠️ 安全提醒：密码仅存储在服务器本地，请勿泄露给非管理员人员</div>
                </form>
            </div>
            <!-- 2. 修改系统标题 -->
            <div class="admin-sub-card">
                <h4>2. 修改系统标题</h4>
                <form method="post" action="">
                    <input type="hidden" name="action" value="save_title">
                    <div style="margin-bottom:10px;"><label>管理员密码：</label><input type="password" name="admin_pass" class="edit-input" required></div>
                    <div><label>新标题：</label><input type="text" name="sys_title" class="edit-input" value="<?php echo htmlspecialchars($sysTitle);?>" required></div>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px;">保存标题</button>
                </form>
            </div>
            <!-- 3. 提交限制开关+时间配置 -->
            <div class="admin-sub-card">
                <h4>3. 提交限制配置</h4>
                <!-- 3.1 开关切换 -->
                <form method="post" action="" class="switch-group">
                    <input type="hidden" name="action" value="toggle_limit_switch">
                    <div style="margin-bottom:10px;"><label>管理员密码：</label><input type="password" name="admin_pass" class="edit-input" required></div>
                    <div class="switch-label">当前状态：<?php echo $limitSwitch === 'on' ? '开启' : '关闭';?></div>
                    <select name="switch_state" class="limit-input" required>
                        <option value="on" <?php echo $limitSwitch === 'on' ? 'selected' : '';?>>开启限制</option>
                        <option value="off" <?php echo $limitSwitch === 'off' ? 'selected' : '';?>>关闭限制</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">切换状态</button>
                </form>
                <!-- 3.2 限制时间修改 -->
                <form method="post" action="" style="margin-top:15px;">
                    <input type="hidden" name="action" value="save_limit_hours">
                    <div style="margin-bottom:10px;">
                        <label>管理员密码：</label>
                        <input type="password" name="admin_pass" class="edit-input" required>
                    </div>
                    <div>
                        <label>限制时间（小时）：</label>
                        <input type="number" name="limit_hours" class="limit-input" min="1" value="<?php echo $submitLimitHours;?>" required>
                        <span style="margin-left:10px;">小时（最小1小时，填写正整数）</span>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px;">保存限制时间</button>
                    <div class="limit-tip">提示：仅当限制开关开启时，此时间配置生效</div>
                </form>
            </div>
            <!-- 4. 调研问题管理 -->
            <div class="admin-sub-card">
                <h4>4. 调研问题管理（增/删/改/排序）</h4>
                <form method="post" action="" style="margin-bottom:15px;">
                    <input type="hidden" name="action" value="add_question">
                    <label>新增问题：</label><input type="text" name="question" class="edit-input" placeholder="输入调研问题" required>
                    <input type="password" name="admin_pass" placeholder="输入管理员密码" class="edit-input" required>
                    <button type="submit" class="btn btn-primary btn-sm">添加</button>
                </form>
                <?php if(!empty($questions)):foreach($questions as $index=>$q):?>
                <div class="question-item">
                    <span><?php echo ($index+1).'. '.htmlspecialchars($q);?></span>
                    <div>
                        <form method="post" action="" style="display:inline;">
                            <input type="hidden" name="action" value="edit_question">
                            <input type="hidden" name="q_index" value="<?php echo $index;?>">
                            <input type="text" name="new_content" value="<?php echo htmlspecialchars($q);?>" class="edit-input" style="width:200px;">
                            <input type="hidden" name="admin_pass" value="<?php echo $adminCurrentPass;?>">
                            <button type="submit" class="btn btn-primary btn-sm">编辑</button>
                        </form>
                        <form method="post" action="" style="display:inline;">
                            <input type="hidden" name="action" value="sort_question">
                            <input type="hidden" name="q_index" value="<?php echo $index;?>">
                            <input type="hidden" name="admin_pass" value="<?php echo $adminCurrentPass;?>">
                            <button type="submit" name="direction" value="up" class="btn btn-primary btn-sm">上移</button>
                            <button type="submit" name="direction" value="down" class="btn btn-primary btn-sm">下移</button>
                        </form>
                        <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定删除？');">
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="q_index" value="<?php echo $index;?>">
                            <input type="hidden" name="admin_pass" value="<?php echo $adminCurrentPass;?>">
                            <button type="submit" class="btn btn-danger btn-sm">删除</button>
                        </form>
                    </div>
                </div>
                <?php endforeach;else:?><p style="color:#999;">暂无问题，先添加吧~</p><?php endif;?>
            </div>
            <!-- 5. 清空调研数据 -->
            <div class="admin-sub-card">
                <h4>5. 清空调研数据（谨慎操作）</h4>
                <form method="post" action="" onsubmit="return confirm('⚠️ 警告：此操作将删除所有员工提交的调研数据，且无法恢复！\n确认要继续清空吗？');">
                    <input type="hidden" name="action" value="clear_survey_data">
                    <div style="margin-bottom:10px;">
                        <label>管理员密码：</label>
                        <input type="password" name="admin_pass" class="edit-input" required placeholder="输入管理员密码进行验证">
                    </div>
                    <button type="submit" class="btn btn-danger" style="margin-top:5px;">清空所有提交数据</button>
                    <div class="clear-tip">⚠️ 注意：仅删除员工打分/实名记录，系统标题、调研问题、限制时间将保留，操作不可恢复！</div>
                </form>
            </div>
            <!-- 6. 查看结果 -->
            <div class="admin-sub-card">
                <h4>6. 查看调研结果</h4>
                <form method="post" action="happiness_result.php" target="_blank">
                    <input type="hidden" name="action" value="view_answers">
                    <label>管理员密码：</label><input type="password" name="admin_pass" class="edit-input" required>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px;">新窗口查看结果</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // 生成浏览器唯一标识（基于userAgent和屏幕信息）
        function generateBrowserId() {
            const userAgent = navigator.userAgent || 'unknown';
            const screenInfo = `${screen.width}x${screen.height}_${screen.colorDepth}`;
            // 简单哈希，避免过长
            return btoa(userAgent + '|' + screenInfo).substring(0, 32);
        }

        // 页面加载时，设置浏览器标识到隐藏域
        window.onload = function() {
            const browserId = generateBrowserId();
            document.getElementById('browserId').value = browserId;

            // 提交成功后，前端记录状态（配合后端限制）
            const form = document.getElementById('surveyForm');
            form.onsubmit = function() {
                <?php if($limitSwitch === 'on'):?>
                const limitHours = <?php echo $submitLimitHours;?>;
                const expireTime = new Date().getTime() + limitHours * 3600 * 1000;
                localStorage.setItem('survey_submit_expire', expireTime);
                <?php endif;?>
                return true;
            }
        }

        // 前端提前校验（可选，提升用户体验）
        <?php if($limitSwitch === 'on'):?>
        const expireTime = localStorage.getItem('survey_submit_expire');
        if(expireTime && new Date().getTime() < expireTime) {
            alert('<?php echo $submitLimitHours;?>小时内仅可提交1次，请稍后再试！');
            document.querySelector('.btn-primary').disabled = true;
        }
        <?php endif;?>
    </script>
</body>
</html>
