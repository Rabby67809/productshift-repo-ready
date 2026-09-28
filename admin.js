(function($){
'use strict';

var jobId = SBOP.activeJob || '';
var busy = false;
var retryCount = 0;
var backupTimer = null;
var backupCloseTimer = null;
var backupPercent = 2;
var backupJobId = SBOP.activeBackupJob || '';
var backupFlowToken = 0;
var backupRetryCount = 0;
var activeXhr = null;
var flowToken = 0;
var cancelled = false;
var successTimer = null;
var previewReady = false;
var backupInFlight = false;
var backupWatchdog = null;

function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }
function toast(type,title,msg){
    var icon = type === 'success' ? 'yes-alt' : 'warning';
    var $t = $('<div class="sbop-toast '+type+'"><span class="dashicons dashicons-'+icon+'"></span><div><strong>'+esc(title)+'</strong><span>'+esc(msg||'')+'</span></div></div>');
    $('#sbop-toast-stack').append($t);
    setTimeout(function(){ $t.addClass('is-leaving'); setTimeout(function(){ $t.remove(); },260); },5200);
}
function showProgress(show){ $('#sbop-progress').toggle(!!show); if(show){ $('#sbop-success-panel').hide(); } }
function setButtons(){
    $('#sbop-start-restore').prop('disabled', busy || previewReady);
    $('#sbop-confirm-restore').toggle(!!jobId && previewReady && !busy);
    $('#sbop-resume-restore').toggle(!!jobId && !previewReady && !busy);
    $('#sbop-cancel-restore').toggle(!!jobId);
    $('#sbop-conflict-mode').prop('disabled', previewReady);
}
function ring(p){ var circ=125.66; $('#sbop-ring-progress').css('stroke-dashoffset',circ-(circ*p/100)); }
function stage(name){
    var order=['validate','media','products','variations','finalize'];
    var idx=order.indexOf(name);
    $('#sbop-stage-row span').each(function(){
        var i=order.indexOf($(this).data('stage'));
        $(this).toggleClass('done',idx>i).toggleClass('active',idx===i);
    });
}
function inferStage(data){
    var label=(data.stage_label||'').toLowerCase();
    if(label.indexOf('valid')>-1||label.indexOf('prepar')>-1||label.indexOf('recovery')>-1||label.indexOf('rollback')>-1)return'validate';
    if(label.indexOf('media')>-1)return'media';
    if(label.indexOf('variation')>-1)return'variations';
    if(label.indexOf('final')>-1||data.complete)return'finalize';
    return'products';
}
function renderPreview(data){
    if(!data || !data.preview){ return; }
    jobId = data.job_id || jobId;
    previewReady = true;
    busy = false;
    if(data.conflict_mode){ $('#sbop-conflict-mode').val(data.conflict_mode); }
    var p=data.preview;
    $('#sbop-preview-products').text(p.products_total||0);
    $('#sbop-preview-create').text(p.will_create||0);
    $('#sbop-preview-update').text(p.will_update||0);
    $('#sbop-preview-skip').text(p.will_skip||0);
    $('#sbop-preview-media').text(p.media_total||0);
    $('#sbop-preview-variations').text(p.variations_total||0);
    var blocks=[];
    if(p.conflicts && p.conflicts.length){
        var rows='<strong>SKU conflicts detected</strong><ul>';
        p.conflicts.forEach(function(c){ rows+='<li><code>'+esc(c.sku||'')+'</code> · '+esc(c.name||'Product')+' · existing ID '+esc(c.existing_id||'')+'</li>'; });
        rows+='</ul>';
        blocks.push(rows);
    }
    if(p.warnings && p.warnings.length){
        var warns='<strong>Review notes</strong><ul>';
        p.warnings.forEach(function(w){ warns+='<li>'+esc(w)+'</li>'; });
        warns+='</ul>';
        blocks.push(warns);
    }
    if(blocks.length){ $('#sbop-preview-conflicts').html(blocks.join('')).show(); } else { $('#sbop-preview-conflicts').hide().empty(); }
    showProgress(false);
    $('#sbop-dry-run').stop(true,true).slideDown(220);
    setButtons();
    toast('success','Dry run complete',SBOP.strings.preview||'Review the restore impact before confirming.');
}
function render(data){
    if(!data)return;
    jobId=data.job_id||jobId;
    if(data.preview_ready){ renderPreview(data); return; }
    previewReady=false;
    $('#sbop-dry-run').hide();
    var p=Math.max(0,Math.min(100,parseInt(data.percent||0,10)));
    showProgress(true);
    $('#sbop-progress-fill').css('width',p+'%');
    $('#sbop-progress-percent').text(p+'%');
    ring(p);
    $('#sbop-progress-label').text(data.stage_label||'Restoring');
    stage(inferStage(data));
    var c=data.counts||{};
    var detailText='';
    if(data.stage==='rollback'){
        detailText='Recovery point '+(c.rollback_done||0)+'/'+(c.rollback_total||0)+' · No store data has been changed yet.';
    }else{
        detailText='Media '+(c.media_done||0)+'/'+(c.media_total||0)+' · Products '+(c.products_done||0)+'/'+(c.products_total||0)+' · Variations '+(c.variations_done||0)+'/'+(c.variations_total||0)+' · Skipped '+(c.skipped||0)+' · Errors '+(c.errors||0);
    }
    $('#sbop-progress-details').text(detailText);
    var errors=data.errors||[];
    if(errors.length){
        var html='<strong>Recent item errors</strong><ul>';
        errors.forEach(function(e){ html+='<li>'+esc(e.scope)+' '+esc(e.item)+': '+esc(e.message)+'</li>'; });
        html+='</ul>';
        $('#sbop-error-box').html(html).show();
    }
    if(data.complete){
        var c2=data.counts||{};
        var hasItemErrors=(c2.errors||0)>0;
        $('#sbop-progress-kicker').text(data.source==='rollback'?'ROLLBACK COMPLETE':(hasItemErrors?'RESTORE COMPLETE WITH WARNINGS':'RESTORE COMPLETE'));
        $('#sbop-progress-label').text(data.source==='rollback'?'Rollback completed successfully':(hasItemErrors?'Restore completed with warnings':'Restore completed successfully'));
        $('#sbop-progress-fill').css('width','100%');
        $('#sbop-progress-percent').text('100%');
        ring(100); stage('finalize');
        $('#sbop-stage-row span').addClass('done').removeClass('active');
        var summary=(data.source==='rollback'?'Rolled back ':'Restored ')+(c2.products_done||0)+' products, '+(c2.variations_done||0)+' variations and '+(c2.media_done||0)+' media files.';
        if(c2.errors)summary+=' '+c2.errors+' item error(s) were logged.';
        if(data.rollback_available && data.source!=='rollback') summary+=' A rollback point is available in Recovery Center.';
        jobId=''; busy=false; cancelled=false; previewReady=false; flowToken++;
        if(activeXhr){activeXhr=null;}
        setButtons();
        $('#sbop-success-summary').text(summary);
        $('#sbop-success-panel').stop(true,true).slideDown(220);
        toast(hasItemErrors?'warning':'success',data.source==='rollback'?'Rollback complete':(hasItemErrors?'Restored with warnings':'Successfully restored'),summary);
        clearTimeout(successTimer);
        successTimer=setTimeout(function(){
            $('#sbop-progress').slideUp(260);
            $('#sbop-success-panel').fadeOut(320);
            if($('#sbop-restore-form')[0]) $('#sbop-restore-form')[0].reset();
            $('#sbop-selected-file').text('No file selected');
            $('#sbop-progress-fill').css('width','0%');
            $('#sbop-progress-percent').text('0%');
            ring(0);
        },2800);
    }
    setButtons();
}
function recoverRestoreState(token,reason){
    if(!jobId||cancelled||token!==flowToken)return;
    $.ajax({url:SBOP.ajaxUrl,method:'POST',dataType:'json',timeout:30000,data:{action:'sbop_restore_status',nonce:SBOP.nonce,job_id:jobId}}).done(function(resp){
        if(token!==flowToken||cancelled)return;
        if(resp&&resp.success&&resp.data){
            retryCount=0;
            if(resp.data.preview_ready){
                busy=false; renderPreview(resp.data);
                if(reason)toast('error','Restore not started',reason);
                return;
            }
            busy=true; render(resp.data);
            if(!resp.data.complete){setTimeout(function(){ajaxStep(token);},300);}
        }else{
            fail(reason||(resp&&resp.data&&resp.data.message?resp.data.message:SBOP.strings.failed));
        }
    }).fail(function(){fail(reason||SBOP.strings.failed);});
}
function ajaxStep(token){
    token=token==null?flowToken:token;
    if(!jobId||cancelled||token!==flowToken)return;
    activeXhr=$.ajax({
        url:SBOP.ajaxUrl,method:'POST',dataType:'json',timeout:120000,
        data:{action:'sbop_restore_step',nonce:SBOP.nonce,job_id:jobId}
    }).done(function(resp){
        if(token!==flowToken||cancelled)return;
        activeXhr=null;
        if(resp&&resp.success){
            retryCount=0; render(resp.data);
            if(resp.data&&!resp.data.complete&&!resp.data.preview_ready&&token===flowToken&&!cancelled){ setTimeout(function(){ajaxStep(token);},50); }
        }else{
            if(resp&&resp.data&&resp.data.cancelled){handleCancelled();return;}
            fail(resp&&resp.data&&resp.data.message?resp.data.message:SBOP.strings.failed);
        }
    }).fail(function(xhr,status){
        if(token!==flowToken||cancelled||status==='abort')return;
        activeXhr=null;
        var msg=SBOP.strings.failed, wasCancelled=false;
        try{var j=JSON.parse(xhr.responseText);if(j&&j.data&&j.data.message)msg=j.data.message;if(j&&j.data&&j.data.cancelled)wasCancelled=true;}catch(e){}
        if(wasCancelled){handleCancelled();return;}
        if(retryCount<4){
            retryCount++;
            $('#sbop-progress-kicker').text('CONNECTION INTERRUPTED');
            $('#sbop-progress-label').text('Retrying restore batch…');
            setTimeout(function(){if(token===flowToken&&!cancelled)ajaxStep(token);},Math.min(6000,700*Math.pow(2,retryCount-1)));
            return;
        }
        recoverRestoreState(token,msg);
    });
}
function handleCancelled(){
    cancelled=true; flowToken++; jobId=''; busy=false; previewReady=false;
    if(activeXhr){try{activeXhr.abort();}catch(e){}activeXhr=null;}
    $('#sbop-dry-run').hide(); setButtons();
    $('#sbop-progress-kicker').text('RESTORE CANCELLED');
    $('#sbop-progress-label').text(SBOP.strings.cancelled);
    $('#sbop-progress-details').text('The restore job was stopped. It will not restart automatically.');
    toast('error','Restore cancelled','The restore job was stopped and will not restart.');
    setTimeout(function(){$('#sbop-progress').slideUp(220);},1200);
}
function fail(msg){
    busy=false; showProgress(true);
    $('#sbop-progress-kicker').text('RESTORE PAUSED');
    $('#sbop-progress-label').text('Restore paused');
    $('#sbop-error-box').html('<strong>'+esc(msg)+'</strong><p>Your saved restore job was not discarded. Press Resume Restore to continue.</p>').show();
    setButtons(); toast('error','Restore paused',msg);
}

$('#sbop-backup-zip').on('change',function(){
    var f=this.files&&this.files[0];
    $('#sbop-selected-file').text(f?f.name+' · '+Math.max(1,Math.round(f.size/1024/1024))+' MB':'No file selected');
});
var dz=$('.sbop-dropzone');
dz.on('dragover dragenter',function(e){e.preventDefault();dz.addClass('is-drag');}).on('dragleave drop',function(e){e.preventDefault();dz.removeClass('is-drag');});
dz.on('drop',function(e){var files=e.originalEvent.dataTransfer.files;if(files&&files.length){var input=$('#sbop-backup-zip')[0];try{input.files=files;$(input).trigger('change');}catch(err){}}});

$('#sbop-restore-form').on('submit',function(e){
    e.preventDefault(); if(busy||previewReady)return;
    var input=$('#sbop-backup-zip')[0];
    if(!input.files||!input.files.length){toast('error','Choose a backup ZIP',SBOP.strings.chooseFile);return;}
    if(SBOP.maxUploadBytes&&input.files[0].size>parseInt(SBOP.maxUploadBytes,10)){toast('error','File is too large','Increase your PHP upload limit or create a smaller backup ZIP.');return;}
    var fd=new FormData(this);
    fd.append('action','sbop_restore_start'); fd.append('nonce',SBOP.nonce);
    busy=true; retryCount=0; cancelled=false; previewReady=false; flowToken++; var token=flowToken; jobId='';
    clearTimeout(successTimer); $('#sbop-success-panel').hide(); $('#sbop-dry-run').hide(); setButtons(); showProgress(true);
    $('#sbop-progress-kicker').text('ANALYZING BACKUP');
    $('#sbop-progress-label').text(SBOP.strings.starting);
    $('#sbop-progress-fill').css('width','8%'); $('#sbop-progress-percent').text('8%'); ring(8); stage('validate'); $('#sbop-error-box').hide().empty();
    activeXhr=$.ajax({url:SBOP.ajaxUrl,method:'POST',data:fd,processData:false,contentType:false,dataType:'json',timeout:120000}).done(function(resp){
        if(token!==flowToken||cancelled)return; activeXhr=null;
        if(resp&&resp.success){ jobId=resp.data.job_id; render(resp.data); }
        else fail(resp&&resp.data&&resp.data.message?resp.data.message:'Could not analyze the backup.');
    }).fail(function(xhr,status){
        if(token!==flowToken||cancelled||status==='abort')return; activeXhr=null;
        var msg='Could not upload or analyze the backup ZIP.';
        try{var j=JSON.parse(xhr.responseText);if(j&&j.data&&j.data.message)msg=j.data.message;}catch(e){}
        fail(msg);
    });
});

$('#sbop-confirm-restore').on('click',function(){
    if(!jobId||busy||!previewReady)return;
    busy=true; previewReady=false; cancelled=false; flowToken++; var token=flowToken; setButtons();
    $('#sbop-dry-run').slideUp(160); showProgress(true);
    $('#sbop-progress-kicker').text('STARTING RESTORE');
    $('#sbop-progress-label').text('Preparing a resumable recovery point…');
    $('#sbop-progress-fill').css('width','2%'); $('#sbop-progress-percent').text('2%'); ring(2); stage('validate');
    activeXhr=$.ajax({url:SBOP.ajaxUrl,method:'POST',dataType:'json',timeout:120000,data:{action:'sbop_restore_confirm',nonce:SBOP.nonce,job_id:jobId}}).done(function(resp){
        if(token!==flowToken||cancelled)return; activeXhr=null;
        if(resp&&resp.success){ busy=true; render(resp.data); if(resp.data&&!resp.data.complete)ajaxStep(token); }
        else {
            var msg=resp&&resp.data&&resp.data.message?resp.data.message:'Could not confirm restore.';
            recoverRestoreState(token,msg);
        }
    }).fail(function(xhr,status){
        if(token!==flowToken||cancelled||status==='abort')return; activeXhr=null;
        var msg='The confirmation response was interrupted. Checking the saved restore state…';
        try{var parsed=JSON.parse(xhr.responseText);if(parsed&&parsed.data&&parsed.data.message)msg=parsed.data.message;}catch(e){}
        recoverRestoreState(token,msg);
    });
});

$('#sbop-resume-restore').on('click',function(){
    if(!jobId||busy||previewReady)return;
    busy=true; retryCount=0; cancelled=false; flowToken++; var token=flowToken; setButtons(); showProgress(true);
    $('#sbop-progress-kicker').text('RESTORE RESUMED'); $('#sbop-progress-label').text(SBOP.strings.working); ajaxStep(token);
});
$('#sbop-cancel-restore').on('click',function(){
    if(!jobId)return;
    if(!confirm(previewReady?'Cancel this dry-run restore job?':'Cancel this restore job? Already restored items will not be deleted.'))return;
    var cancellingJob=jobId; cancelled=true; flowToken++;
    if(activeXhr){try{activeXhr.abort();}catch(e){}activeXhr=null;}
    busy=false; setButtons(); $('#sbop-progress-kicker').text('CANCELLING…'); $('#sbop-progress-label').text('Stopping restore safely…');
    $.ajax({url:SBOP.ajaxUrl,method:'POST',dataType:'json',data:{action:'sbop_restore_cancel',nonce:SBOP.nonce,job_id:cancellingJob}}).always(function(){handleCancelled();});
});

function openBackupModal(kind){
    backupPercent=2; backupInFlight=true; clearTimeout(backupCloseTimer); clearTimeout(backupWatchdog);
    $('#sbop-backup-modal').removeClass('is-complete is-error').fadeIn(150).css('display','grid');
    var title=kind==='media'?'Preparing media backup':(kind==='incremental'?'Preparing incremental backup':'Preparing product backup');
    $('#sbop-backup-modal-title').text(title);
    $('#sbop-backup-modal-text').text('Starting a resumable backup job…');
    $('#sbop-backup-progress-fill').css('width','2%'); $('#sbop-backup-progress-text').text('2%'); $('#sbop-close-backup-modal').hide();
    $('.sbop-export-form button[type="submit"]').prop('disabled',true);
}
function closeBackupModal(){clearTimeout(backupCloseTimer);$('#sbop-backup-modal').stop(true,true).fadeOut(260,function(){$(this).removeClass('is-complete is-error');});}
function prependBackupRow(data){
    if(!data||!data.backup_id)return;
    $('#sbop-backup-empty').remove();
    var $list=$('#sbop-backup-recent-list'); if(!$list.length)return;
    var $row=$('<div class="sbop-history-row"></div>').attr('data-backup-id',data.backup_id);
    var $main=$('<div></div>').append($('<strong></strong>').text(data.filename||'backup.zip')).append($('<small></small>').text((data.created_label||'')+' · '+(data.size_human||'')));
    var $meta=$('<div class="sbop-history-meta"></div>').append($('<span></span>').text(data.type_label||'Backup')).append($('<span></span>').text((data.product_count||0)+' products')).append($('<span></span>').text((data.media_count||0)+' media'));
    var $actions=$('<div class="sbop-history-actions"></div>');
    if(data.download_url)$actions.append($('<a class="sbop-btn sbop-btn-soft">Download</a>').attr('href',data.download_url));
    if(data.delete_url)$actions.append($('<a class="sbop-btn sbop-btn-danger sbop-delete-backup">Delete</a>').attr('href',data.delete_url));
    $row.append($main,$meta,$actions); $list.prepend($row);
    $list.children('.sbop-history-row').slice(5).remove();
}
function renderBackupProgress(data){
    if(!data)return;
    backupJobId=data.job_id||backupJobId;
    var p=Math.max(2,Math.min(99,parseInt(data.percent||2,10)));
    backupPercent=p;
    $('#sbop-backup-progress-fill').css('width',p+'%'); $('#sbop-backup-progress-text').text(p+'%');
    if(data.stage_label){ $('#sbop-backup-modal-title').text(data.stage_label); }
    var details=[];
    if(data.product_count){details.push(data.product_count+' products');}
    if(data.variation_count){details.push(data.variation_count+' variations');}
    if(data.media_count){details.push(data.media_count+' media');}
    if(data.error_count){details.push(data.error_count+' skipped errors');}
    $('#sbop-backup-modal-text').text(details.length?details.join(' · '):'Processing the backup in small resumable batches…');
}
function finishBackup(data){
    clearTimeout(backupWatchdog); backupInFlight=false; backupJobId=''; backupRetryCount=0; backupPercent=100;
    $('.sbop-export-form button[type="submit"]').prop('disabled',false);
    $('#sbop-backup-progress-fill').css('width','100%'); $('#sbop-backup-progress-text').text('100%'); $('#sbop-backup-modal').addClass('is-complete').removeClass('is-error');
    $('#sbop-backup-modal-title').text('Backup completed successfully'); $('#sbop-backup-modal-text').text(data&&data.message?data.message:'Your backup was verified and saved. The download is starting now.'); $('#sbop-close-backup-modal').show();
    prependBackupRow(data||{});
    toast('success','Backup saved','The backup is in Backup History and can be downloaded again.');
    if(data&&data.download_url){ $('#sbop-download-frame').attr('src',data.download_url); }
    backupCloseTimer=setTimeout(closeBackupModal,2300);
}
function failBackup(message,keepJob){
    clearTimeout(backupWatchdog); backupInFlight=false;
    $('.sbop-export-form button[type="submit"]').prop('disabled',false);
    $('#sbop-backup-modal').removeClass('is-complete').addClass('is-error');
    $('#sbop-backup-modal-title').text(keepJob?'Backup paused':'Backup could not be completed');
    $('#sbop-backup-modal-text').text(message||(keepJob?'The connection stopped. Reload this Backup page to resume the saved job.':'The backup request failed.'));
    $('#sbop-backup-progress-text').text(keepJob?backupPercent+'%':'Failed'); $('#sbop-close-backup-modal').show();
    toast('error',keepJob?'Backup paused':'Backup failed',message||'The backup did not complete.');
}
function backupAjaxStep(token){
    if(token!==backupFlowToken||!backupJobId)return;
    $.ajax({url:SBOP.ajaxUrl,method:'POST',dataType:'json',timeout:60000,data:{action:'sbop_backup_step',nonce:SBOP.backupNonce,job_id:backupJobId}}).done(function(resp){
        if(token!==backupFlowToken)return;
        if(resp&&resp.success){
            backupRetryCount=0;
            if(resp.data&&resp.data.complete){ finishBackup(resp.data); return; }
            renderBackupProgress(resp.data||{});
            setTimeout(function(){backupAjaxStep(token);},80);
        }else{
            var msg=resp&&resp.data&&resp.data.message?resp.data.message:'The backup step failed.';
            failBackup(msg,true);
        }
    }).fail(function(xhr,status){
        if(token!==backupFlowToken||status==='abort')return;
        backupRetryCount++;
        var msg='The server did not respond to this backup batch.';
        try{var parsed=JSON.parse(xhr.responseText);if(parsed&&parsed.data&&parsed.data.message)msg=parsed.data.message;}catch(e){}
        if(backupRetryCount<=4){
            $('#sbop-backup-modal-title').text('Connection interrupted — retrying');
            $('#sbop-backup-modal-text').text(msg+' The saved job will continue from its last checkpoint.');
            setTimeout(function(){backupAjaxStep(token);},Math.min(8000,1000*Math.pow(2,backupRetryCount-1)));
        }else{
            failBackup(msg+' Reload this Backup page to resume from the saved checkpoint.',true);
        }
    });
}
function startBackupJob($form){
    if(backupInFlight)return;
    var kind=$form.data('kind')||'products';
    openBackupModal(kind); backupFlowToken++; var token=backupFlowToken; backupRetryCount=0;
    var payload={action:'sbop_backup_start',nonce:SBOP.backupNonce,kind:kind};
    $form.serializeArray().forEach(function(row){if(row.name!=='action'&&row.name!=='_wpnonce'&&row.name!=='_wp_http_referer'){payload[row.name]=row.value;}});
    if($form.find('input[name="include_images"]').is(':checkbox')){payload.include_images=$form.find('input[name="include_images"]').is(':checked')?'1':'';}
    $.ajax({url:SBOP.ajaxUrl,method:'POST',dataType:'json',timeout:60000,data:payload}).done(function(resp){
        if(token!==backupFlowToken)return;
        if(resp&&resp.success){backupJobId=resp.data.job_id||'';renderBackupProgress(resp.data||{});backupAjaxStep(token);}
        else{failBackup(resp&&resp.data&&resp.data.message?resp.data.message:'Could not start the backup.',false);}
    }).fail(function(xhr){
        var msg='Could not start the resumable backup job.';
        try{var parsed=JSON.parse(xhr.responseText);if(parsed&&parsed.data&&parsed.data.message)msg=parsed.data.message;}catch(e){
            if(xhr&&xhr.status){msg+=' Server returned HTTP '+xhr.status+'.';}
        }
        failBackup(msg,false);
    });
}
window.SBOPBackupComplete=function(data){
    // Legacy no-JavaScript export endpoint fallback.
    if(!data||data.success!==true){ failBackup(data&&data.message?data.message:'The server did not save the backup.',false); return; }
    finishBackup(data);
};
$('.sbop-export-form').on('submit',function(e){e.preventDefault();startBackupJob($(this));return false;});
$('#sbop-close-backup-modal').on('click',closeBackupModal);
if(backupJobId && SBOP.activeBackupData){
    var resumeKind=SBOP.activeBackupData.kind||'products';
    setTimeout(function(){
        openBackupModal(resumeKind); backupFlowToken++; var token=backupFlowToken;
        renderBackupProgress(SBOP.activeBackupData); $('#sbop-backup-modal-title').text('Resuming interrupted backup');
        backupAjaxStep(token);
    },350);
}
$('#sbop-success-close').on('click',function(){clearTimeout(successTimer);$('#sbop-success-panel').fadeOut(160);$('#sbop-progress').slideUp(180);});

if(jobId && SBOP.activeJobData){
    if(SBOP.activeJobData.preview_ready){ renderPreview(SBOP.activeJobData); }
    else{
        previewReady=false; showProgress(true);
        $('#sbop-progress-kicker').text(SBOP.activeJobData.source==='rollback'?'ROLLBACK READY':'RESTORE AVAILABLE');
        $('#sbop-progress-label').text(SBOP.activeJobData.source==='rollback'?'Recovery point is ready to apply':'A previous restore job is ready to resume');
        $('#sbop-progress-details').text('Press Resume Restore. You do not need to upload the ZIP again.');
        stage(inferStage(SBOP.activeJobData));
        if(SBOP.activeJobData.source==='rollback'){
            setTimeout(function(){ if(jobId&&!busy){ $('#sbop-resume-restore').trigger('click'); } },500);
        }
    }
}
setButtons();

$(document).on('click','.sbop-delete-backup',function(e){if(!window.confirm('Delete this saved backup from the server? This cannot be undone.')){e.preventDefault();}});

})(jQuery);

jQuery(document).on('submit','form[data-sbop-confirm]',function(e){
    var message=jQuery(this).attr('data-sbop-confirm')||'';
    if(message&&!window.confirm(message)){e.preventDefault();return false;}
});
