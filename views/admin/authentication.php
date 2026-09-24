<p class="admin-lead">Authentication methods and session health across FitCrew.</p>
<section class="product-card"><h2>Authentication methods</h2><?php fc_admin_table($summary['providers'], ['provider_key'=>'Method','identity_status'=>'Status','total'=>'Identities']); ?></section>
<section class="product-card"><h2>Session status</h2><?php fc_admin_table($summary['sessions'], ['session_status'=>'Status','total'=>'Sessions']); ?></section>
<section class="product-card"><h2>Authentication audit · last 24 hours</h2><?php fc_admin_table($summary['events'], ['event_type'=>'Action','outcome'=>'Result','total'=>'Events']); ?></section>
