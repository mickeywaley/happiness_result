<?php
define('QUESTION_FILE', './survey_questions.txt');
define('ANSWER_FILE', './survey_answers.txt');
define('TITLE_FILE', './survey_title.txt');
define('LIMIT_TIME_FILE', './submit_limit_hours.txt');
define('LIMIT_SWITCH_FILE', './limit_switch.txt');
define('ADMIN_PASS_FILE', './admin_password.txt');

function getAdminPass() {
    if(!file_exists(ADMIN_PASS_FILE)) return '123456';
    $pass = trim(file_get_contents(ADMIN_PASS_FILE));
    return !empty($pass) ? $pass : '123456';
}

// 验证权限
$adminCurrentPass = getAdminPass();
if($_SERVER['REQUEST_METHOD']!=='POST' || ($_POST['admin_pass']??'')!==$adminCurrentPass){
    die('<div style="text-align:center;margin-top:50px;color:#ff4d4f;">密码错误！无权查看结果</div>');
}

// 获取提交限制状态（用于页面显示）
function getLimitSwitch() {
    if(!file_exists(LIMIT_SWITCH_FILE)) return 'on';
    $switch = trim(file_get_contents(LIMIT_SWITCH_FILE));
    return in_array($switch, ['on', 'off']) ? $switch : 'on';
}
function getSubmitLimitHours() {
    if(!file_exists(LIMIT_TIME_FILE)) return 24;
    $hours = trim(file_get_contents(LIMIT_TIME_FILE));
    return is_numeric($hours) && (int)$hours > 0 ? (int)$hours : 24;
}

function getSysTitle(){
    return file_exists(TITLE_FILE)?trim(file_get_contents(TITLE_FILE)):"员工幸福感调研结果统计";
}

function getQuestions(){
    if(!file_exists(QUESTION_FILE)) return [];
    $content=str_replace(["\r\n","\r"],"\n",file_get_contents(QUESTION_FILE));
    $lines=explode("\n",trim($content));
    return array_filter($lines,function($line){return !empty(trim($line));});
}

function parseAnswers(){
    $questions=array_values(getQuestions());
    $qCount=is_countable($questions) ? count($questions) : 0;
    $userList=[];
    $questionAvgs=[];
    $totalAvg=0;
    $error='';

    if($qCount==0){
        $error='请先添加调研问题';
        return ['users'=>$userList,'questions'=>$questions,'questionAvgs'=>$questionAvgs,'totalAvg'=>$totalAvg,'error'=>$error];
    }
    if(!file_exists(ANSWER_FILE)||filesize(ANSWER_FILE)==0){
        $error='暂无提交数据';
        return ['users'=>$userList,'questions'=>$questions,'questionAvgs'=>$questionAvgs,'totalAvg'=>$totalAvg,'error'=>$error];
    }
    
    $content=str_replace(["\r\n","\r"],"\n",file_get_contents(ANSWER_FILE));
    $lines=explode("\n",trim($content));
    $currentUser=null;
    $currentQIdx=0;

    foreach($lines as $line){
        $line=trim($line);
        if(empty($line)) continue;

        if(strpos($line,'SURVEY_RECORD_START')!==false){
            // 匹配新格式：BROWSER_ID 替代 IP
            preg_match('/NAME:([^ ]*) SUBMIT_TIME:(\S+) BROWSER_ID:/', $line, $matches);
            $name = isset($matches[1]) && !empty(trim($matches[1])) ? trim($matches[1]) : '匿名';
            $timeStr = isset($matches[2]) ? $matches[2] : '';
            $currentUser=['name'=>$name,'time'=>$timeStr,'scores'=>array_fill(0,$qCount,0),'feedbacks'=>array_fill(0,$qCount,''),'total'=>0];
            $currentQIdx=0;
            continue;
        }

        if(strpos($line,'SURVEY_RECORD_END')!==false&&$currentUser!==null){
            $currentUser['total']=array_sum($currentUser['scores']);
            $userList[]=$currentUser;
            $currentUser=null;
            continue;
        }

        if(strpos($line,'S:')===0&&$currentUser!==null&&$currentQIdx<$qCount){
            $score=(int)trim(substr($line,2));
            $currentUser['scores'][$currentQIdx]=$score;
            continue;
        }

        if(strpos($line,'F:')===0&&$currentUser!==null&&$currentQIdx<$qCount){
            $currentUser['feedbacks'][$currentQIdx]=trim(substr($line,2));
            $currentQIdx++;
            continue;
        }
    }

    $uCount=is_countable($userList) ? count($userList) : 0;
    if($uCount>0){
        $qAvgs=array_fill(0,$qCount,0);
        $totalScores=array_fill(0,$qCount,0);
        $allScores=[];

        foreach($userList as $u){
            foreach($u['scores'] as $idx=>$s){
                $totalScores[$idx]+=$s;
                $allScores[]=$s;
            }
        }

        foreach($totalScores as $idx=>$sum){
            $qAvgs[$idx]=round($sum/$uCount,2);
        }

        $totalAvg=!empty($allScores) ? round(array_sum($allScores)/count($allScores),2) : 0;
        $questionAvgs=$qAvgs;
    }

    return ['users'=>$userList,'questions'=>$questions,'questionAvgs'=>$questionAvgs,'totalAvg'=>$totalAvg,'error'=>$error];
}

$surveyData=parseAnswers();
$userList=isset($surveyData['users']) && is_array($surveyData['users']) ? $surveyData['users'] : [];
$questions=isset($surveyData['questions']) && is_array($surveyData['questions']) ? $surveyData['questions'] : [];
$questionAvgs=isset($surveyData['questionAvgs']) && is_array($surveyData['questionAvgs']) ? $surveyData['questionAvgs'] : [];
$totalAvg=isset($surveyData['totalAvg']) ? $surveyData['totalAvg'] : 0;
$errorMsg=isset($surveyData['error']) ? $surveyData['error'] : '';

$qCount=is_countable($questions) ? count($questions) : 0;
$uCount=is_countable($userList) ? count($userList) : 0;
$fullTotal=$qCount*10;
$sysTitle=getSysTitle();
$submitLimitHours = getSubmitLimitHours();
$limitSwitch = getLimitSwitch();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?php echo $sysTitle;?>-统计分析表</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:"Microsoft YaHei";}
        body{padding:20px;background:#f5f5f5;}
        .container{max-width:1200px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 15px rgba(0,0,0,0.1);padding:30px;}
        .title{text-align:center;margin-bottom:20px;color:#333;padding-bottom:15px;border-bottom:2px solid #2f96ff;}
        .limit-status{font-size:14px;color:#fff;padding:4px 12px;border-radius:20px;display:inline-block;margin-left:15px;}
        .status-on{background:#52c41a;}
        .status-off{background:#ff4d4f;}
        .error-msg{padding:15px;background:#fff2f0;color:#ff4d4f;text-align:center;border-radius:4px;margin-bottom:20px;}
        .stats-bar{padding:12px;background:#f0f9ff;color:#2f96ff;border-radius:4px;margin-bottom:20px;}
        .result-table{width:100%;border-collapse:collapse;min-width:800px;}
        .result-table th,.result-table td{border:1px solid #e8e8e8;padding:15px 10px;text-align:center;vertical-align:middle;}
        .result-table th{background:#2f96ff;color:#fff;position:sticky;top:0;}
        .result-table tr:nth-child(even){background:#f8f9fa;}
        .score-box{font-size:18px;font-weight:bold;color:#1e88e5;}
        .feedback-box{margin-top:8px;font-size:14px;color:#666;padding-top:8px;border-top:1px dashed #ddd;}
        .feedback-empty{color:#999;}
        .total-cell{color:#ff4d4f;font-weight:600;font-size:16px;}
        .avg-row{background:#f0fff4!important;color:#52c41a;font-weight:600;}
        .name-cell.real-name{
            font-weight: 600;
            color: #2f96ff;
            background-color: #f0f9ff;
            border-radius: 4px;
            padding: 4px 8px;
        }
        .name-cell.anonymous{
            font-weight: 400;
            color: #999;
            font-style: italic;
        }
        .btn-group{margin-top:20px;display:flex;gap:10px;}
        .btn{padding:8px 16px;border:none;border-radius:4px;cursor:pointer;background:#2f96ff;color:#fff;}
    </style>
</head>
<body>
    <div class="container">
        <h1 class="title"><?php echo htmlspecialchars($sysTitle);?>-统计分析表
            <?php if($limitSwitch === 'on'):?>
                <span class="limit-status status-on">限制开启：<?php echo $submitLimitHours;?>小时/次</span>
            <?php else:?>
                <span class="limit-status status-off">限制关闭</span>
            <?php endif;?>
        </h1>
        
        <?php if(!empty($errorMsg)):?>
            <div class="error-msg"><?php echo $errorMsg;?></div>
        <?php else:?>
            <div class="stats-bar">
                问题数：<?php echo $qCount;?>题 | 填写人数：<?php echo $uCount;?>人 | 
                <?php if($uCount>0):?>
                    总体平均分：<?php echo $totalAvg;?>分（满分10分） | 
                <?php else:?>
                    总体平均分：0分（满分10分） | 
                <?php endif;?>
                提交限制：<?php echo $limitSwitch === 'on' ? $submitLimitHours.'小时/次' : '无限制';?>
            </div>
        <?php endif;?>
        
        <?php if($uCount>0&&$qCount>0):?>
            <table class="result-table">
                <thead>
                    <tr>
                        <th>填写人</th>
                        <th>提交时间</th>
                        <?php foreach($questions as $idx=>$q):?>
                        <th style="max-width:220px;word-wrap:break-word;line-height:1.5;">
                            <?php echo ($idx+1).'. '.htmlspecialchars($q);?><br>
                            <small style="opacity:0.8;">平均分:<?php echo isset($questionAvgs[$idx]) ? $questionAvgs[$idx] : 0;?>分</small>
                        </th>
                        <?php endforeach;?>
                        <th>个人总分(满分<?php echo $fullTotal;?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($userList as $u):?>
                    <tr>
                        <td class="name-cell <?php echo $u['name']!='匿名' ? 'real-name' : 'anonymous';?>">
                            <?php echo htmlspecialchars($u['name']);?>
                        </td>
                        <td><?php echo htmlspecialchars($u['time']);?></td>
                        <?php foreach($u['scores'] as $sIdx=>$s):?>
                        <td>
                            <div class="score-box"><?php echo $s;?>分</div>
                            <div class="feedback-box">
                                补充意见：<?php echo !empty($u['feedbacks'][$sIdx])?htmlspecialchars($u['feedbacks'][$sIdx]):'<span class="feedback-empty">无补充意见</span>';?>
                            </div>
                        </td>
                        <?php endforeach;?>
                        <td class="total-cell"><?php echo $u['total'];?></td>
                    </tr>
                    <?php endforeach;?>
                    <tr class="avg-row">
                        <td colspan="2">每题平均分</td>
                        <?php foreach($questionAvgs as $avg):?>
                            <td><?php echo $avg;?>分</td>
                        <?php endforeach;?>
                        <td>总体平均分: <?php echo $totalAvg;?>分</td>
                    </tr>
                </tbody>
            </table>
            <div class="btn-group">
                <button class="btn" onclick="window.print()">打印结果</button>
                <button class="btn" onclick="location.reload()">刷新数据</button>
            </div>
        <?php elseif(empty($errorMsg)):?>
            <div style="text-align:center;padding:50px;color:#999;">暂无有效提交数据，等待员工提交后即可显示</div>
        <?php endif;?>
    </div>
</body>
</html>
