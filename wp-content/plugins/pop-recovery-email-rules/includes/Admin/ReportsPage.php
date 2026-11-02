<?php
declare(strict_types=1);
namespace POPRecoveryEmail\Admin;
defined('ABSPATH') || exit;

final class ReportsPage {
    public function render(): void {
        global $wpdb;
        $seq=$wpdb->prefix.'po_email_sequences'; $log=$wpdb->prefix.'po_email_log';
        $period=sanitize_text_field($_GET['period']??'30d');
        $days=match($period){'today'=>0,'7d'=>7,'30d'=>30,'90d'=>90,default=>30};
        $start=$days===0?gmdate('Y-m-d').' 00:00:00':gmdate('Y-m-d H:i:s',strtotime("-{$days} days"));

        $s=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) as t, SUM(status IN ('sent','recovered','paid_direct')) as sent, SUM(opened_at IS NOT NULL) as op, SUM(clicked_at IS NOT NULL) as cl, SUM(landed_at IS NOT NULL) as ld, SUM(pay_attempts>0) as at, SUM(status='recovered') as rc, SUM(IF(status='recovered',order_total,0)) as rv, SUM(cancelled_reason='unsubscribed') as us FROM {$seq} WHERE created_at>=%s",$start));
        $sent=(int)($s->sent??0);$op=(int)($s->op??0);$cl=(int)($s->cl??0);$ld=(int)($s->ld??0);$at=(int)($s->at??0);$rc=(int)($s->rc??0);$rv=(float)($s->rv??0);$all=(int)($s->t??0);
        $oR=$sent>0?round($op/$sent*100,1):0;$cR=$sent>0?round($cl/$sent*100,1):0;$rR=$all>0?round($rc/$all*100,1):0;

        $trend=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) as d,COUNT(*) as t,SUM(status IN ('sent','recovered','paid_direct')) as s,SUM(status='recovered') as r,SUM(IF(status='recovered',order_total,0)) as rv FROM {$seq} WHERE created_at>=%s GROUP BY DATE(created_at) ORDER BY d DESC",$start));

        $pg=max(1,(int)($_GET['hp']??1));$pp=25;
        $ht=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$log} WHERE sent_at>=%s",$start));
        $hist=$wpdb->get_results($wpdb->prepare("SELECT l.*,s.status as ss FROM {$log} l LEFT JOIN {$seq} s ON s.id=l.sequence_id WHERE l.sent_at>=%s ORDER BY l.sent_at DESC LIMIT %d OFFSET %d",$start,$pp,($pg-1)*$pp));

        $recSrc=[];$po=$wpdb->prefix.'po_failed_transactions';
        if($wpdb->get_var("SHOW TABLES LIKE '{$po}'")){$recSrc=$wpdb->get_results($wpdb->prepare("SELECT COALESCE(recovery_source,'auto_redirect') as src,COUNT(*) as c,SUM(order_total) as rv FROM {$po} WHERE recovered=1 AND created_at>=%s GROUP BY src ORDER BY rv DESC",$start))?:[];}

        $periods=['today'=>'Today','7d'=>'7 Days','30d'=>'30 Days','90d'=>'90 Days'];
        ?>
        <div class="prer-period-bar">
            <?php foreach($periods as $k=>$l): ?>
            <a href="<?php echo esc_url(admin_url("admin.php?page=pop-email-recovery&tab=reports&period={$k}"));?>" class="prer-period-chip <?php echo $period===$k?'active':'';?>"><?php echo esc_html($l);?></a>
            <?php endforeach; ?>
        </div>

        <div class="prer-stats prer-stats-6">
            <?php $kpis=[['Sent',number_format($sent),'#e0f2fe','#0284c7'],['Open Rate',$oR.'%','#fef3c7','#d97706'],['Click Rate',$cR.'%','#dbeafe','#2563eb'],['Recovery',$rR.'%','#dcfce7','#16a34a']];
            foreach($kpis as [$l,$v,$bg,$fg]):?><div class="prer-stat-card"><div class="prer-stat-icon" style="background:<?php echo $bg;?>;color:<?php echo $fg;?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg></div><div><div class="prer-stat-value"><?php echo $v;?></div><div class="prer-stat-label"><?php echo $l;?></div></div></div><?php endforeach;?>
            <div class="prer-stat-card prer-stat-highlight"><div class="prer-stat-icon" style="background:rgba(255,255,255,.15);color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div><div class="prer-stat-value"><?php echo wc_price($rv);?></div><div class="prer-stat-label">Revenue</div></div></div>
            <div class="prer-stat-card"><div class="prer-stat-icon" style="background:#fce7f3;color:#db2777"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 7 7 17"/><path d="M7 7h10v10"/></svg></div><div><div class="prer-stat-value"><?php echo (int)($s->us??0);?></div><div class="prer-stat-label">Unsubs</div></div></div>
        </div>

        <div class="prer-reports-grid">
        <div class="prer-card"><h2 class="prer-card-title">Funnel</h2><div class="prer-funnel">
        <?php /* 1.5.9: full funnel; success = clicked AND paid the same order via the email's pay link */
        $fn=[['Sent',$sent,'#94a3b8'],['Opened',$op,'#f59e0b'],['Clicked',$cl,'#3b82f6'],['Landed on pay page',$ld,'#6366f1'],['Attempted payment',$at,'#8b5cf6'],['Paid via link',$rc,'#22c55e']];$mx=max($sent,1);
        foreach($fn as [$fl,$fv,$fc]):$fp=round($fv/$mx*100);?><div class="prer-funnel-step"><div class="prer-funnel-info"><span class="prer-funnel-label"><?php echo $fl;?></span><span class="prer-funnel-value"><?php echo number_format($fv);?></span></div><div class="prer-funnel-bar-bg"><div class="prer-funnel-bar" style="width:<?php echo max($fp,2);?>%;background:<?php echo $fc;?>"></div></div></div><?php endforeach;?></div></div>

        <div class="prer-card"><h2 class="prer-card-title">Attribution</h2>
        <?php if(!empty($recSrc)):$sl=['auto_redirect'=>'Payment Orchestrator','email'=>'Email Recovery','sms'=>'SMS','manual'=>'Manual'];?>
        <table class="prer-table"><thead><tr><th>Source</th><th>Count</th><th>Revenue</th></tr></thead><tbody>
        <?php foreach($recSrc as $r):?><tr><td><span class="prer-source-dot <?php echo esc_attr($r->src);?>"></span><?php echo esc_html($sl[$r->src]??ucfirst($r->src));?></td><td><?php echo number_format((int)$r->c);?></td><td class="prer-revenue"><?php echo wc_price((float)$r->rv);?></td></tr><?php endforeach;?></tbody></table>
        <?php else:?><p class="prer-empty-inline">No data.</p><?php endif;?></div>

        <div class="prer-card prer-card-wide"><h2 class="prer-card-title">Daily Activity</h2>
        <table class="prer-table"><thead><tr><th>Date</th><th>Queued</th><th>Sent</th><th>Recovered</th><th>Rate</th><th>Revenue</th></tr></thead><tbody>
        <?php foreach($trend as $t):$tt=(int)$t->t;$tr=(int)$t->r;$trt=$tt>0?round($tr/$tt*100,1):0;?>
        <tr><td><?php echo esc_html(date_i18n('M j',strtotime($t->d)));?></td><td><?php echo $tt;?></td><td><?php echo (int)$t->s;?></td><td><?php echo $tr;?></td>
        <td><div class="prer-mini-bar-wrap"><div class="prer-mini-bar-bg"><div class="prer-mini-bar" style="width:<?php echo max($trt,2);?>%"></div></div><span class="prer-pct"><?php echo $trt;?>%</span></div></td>
        <td class="prer-revenue"><?php echo wc_price((float)$t->rv);?></td></tr>
        <?php endforeach;if(empty($trend)):?><tr><td colspan="6" class="prer-empty">No data.</td></tr><?php endif;?></tbody></table></div>

        <div class="prer-card prer-card-wide"><h2 class="prer-card-title">Send History</h2>
        <table class="prer-table"><thead><tr><th>Sent</th><th>Order</th><th>Customer</th><th>Subject</th><th>Amount</th><th>Opened</th><th>Clicked</th><th>Converted</th></tr></thead><tbody>
        <?php foreach($hist as $h):$cv=(int)$h->recovered===1;?>
        <tr><td class="prer-nowrap"><?php echo esc_html(get_date_from_gmt($h->sent_at,'M j, g:ia'));?></td>
        <td><a href="<?php echo esc_url(DashboardPage::orderEditUrl((int)$h->order_id));?>" class="prer-order-link">#<?php echo (int)$h->order_id;?></a></td>
        <td class="prer-email-cell"><?php echo esc_html($h->customer_email);?></td>
        <td class="prer-subject-cell"><?php echo esc_html($h->subject);?></td>
        <td><?php echo wc_price((float)$h->order_total);?></td>
        <td><?php echo $h->opened_at?'<span class="prer-badge-yes">Yes</span>':'<span class="prer-muted">' . "\u{2014}" . '</span>';?></td>
        <td><?php echo $h->clicked_at?'<span class="prer-badge-yes">Yes</span>':'<span class="prer-muted">' . "\u{2014}" . '</span>';?></td>
        <td><?php echo $cv?'<span class="prer-badge-converted">Recovered</span>':($h->ss==='cancelled'?'<span class="prer-muted">Cancelled</span>':'<span class="prer-muted">' . "\u{2014}" . '</span>');?></td></tr>
        <?php endforeach;if(empty($hist)):?><tr><td colspan="8" class="prer-empty">No emails.</td></tr><?php endif;?></tbody></table>
        <?php $tp=ceil($ht/$pp);if($tp>1):?><div class="prer-pagination"><?php for($p=1;$p<=$tp;$p++):?><a href="<?php echo esc_url(add_query_arg(['hp'=>$p,'period'=>$period,'tab'=>'reports']));?>" class="prer-page-btn <?php echo $pg===$p?'active':'';?>"><?php echo $p;?></a><?php endfor;?></div><?php endif;?>
        </div>
        </div>
        <?php
    }
}
