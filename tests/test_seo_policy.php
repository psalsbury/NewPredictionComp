<?php
require __DIR__.'/../seo-policy.php';
function expectPolicy(bool $actual,bool $expected,string $name):void {
    if($actual!==$expected)throw new RuntimeException($name);
    echo "PASS ".$name.PHP_EOL;
}
$fixture=['kickoff_utc'=>gmdate('Y-m-d H:i:s',strtotime('+13 days')),'status'=>'NS','home_score'=>null,'away_score'=>null];
expectPolicy(pcSeoPreviewReady($fixture),true,'near-term preview indexable');
$fixture['kickoff_utc']=gmdate('Y-m-d H:i:s',strtotime('+15 days'));
expectPolicy(pcSeoPreviewReady($fixture),false,'distant fixture excluded');
$fixture['kickoff_utc']=gmdate('Y-m-d H:i:s',strtotime('-1 day'));
$fixture['status']='FT';$fixture['home_score']=0;$fixture['away_score']=0;
expectPolicy(pcSeoPreviewReady($fixture),true,'completed goalless review retained');
expectPolicy(pcSeoGuideReady(date('Y-m-d',strtotime('+13 days'))),true,'near-term guide indexable');
expectPolicy(pcSeoGuideReady(date('Y-m-d',strtotime('+15 days'))),false,'distant guide excluded');
