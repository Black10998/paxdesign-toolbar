<?php
/**
 * Plugin updates panel — GitHub release checker.
 *
 * @var array<string, mixed> $pdx_update_status
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status   = $pdx_update_status ?? PDX_Updater::instance()->get_status( false );
$plugins  = admin_url( 'plugins.php' );
$check_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=pdx_check_updates' ),
	'pdx_check_updates'
);
$maint_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=pdx_clear_maintenance' ),
	'pdx_clear_maintenance'
);
?>
<section class="pdx-section pdx-updates-panel" id="pdx-updates">
	<header class="pdx-section__head"><h2>Updates</h2></header>
	<div class="pdx-section__body">
		<p class="pdx-field-hint" style="margin-top:0;margin-bottom:var(--pdx-space-4)">
			Releases are published on GitHub. WordPress checks automatically; use the button below to refresh release metadata immediately.
		</p>

		<dl class="pdx-updates-meta">
			<div class="pdx-updates-meta__row">
				<dt>Installed version</dt>
				<dd><code><?php echo esc_html( $status['installed'] ); ?></code></dd>
			</div>
			<div class="pdx-updates-meta__row">
				<dt>Latest available</dt>
				<dd>
					<?php if ( ! empty( $status['latest'] ) ) : ?>
						<code><?php echo esc_html( $status['latest'] ); ?></code>
					<?php elseif ( ! empty( $status['error'] ) ) : ?>
						<span class="pdx-updates-meta__warn">Could not determine</span>
					<?php else : ?>
						<span class="pdx-updates-meta__muted">—</span>
					<?php endif; ?>
				</dd>
			</div>
			<div class="pdx-updates-meta__row">
				<dt>Status</dt>
				<dd>
					<?php if ( ! empty( $status['update_available'] ) ) : ?>
						<span class="pdx-updates-badge pdx-updates-badge--available">Update available</span>
					<?php elseif ( ! empty( $status['error'] ) ) : ?>
						<span class="pdx-updates-badge pdx-updates-badge--error">Check failed</span>
					<?php else : ?>
						<span class="pdx-updates-badge pdx-updates-badge--ok">Up to date</span>
					<?php endif; ?>
				</dd>
			</div>
			<div class="pdx-updates-meta__row">
				<dt>Last checked</dt>
				<dd>
					<?php if ( ! empty( $status['checked_at_formatted'] ) ) : ?>
						<?php echo esc_html( $status['checked_at_formatted'] ); ?>
					<?php else : ?>
						<span class="pdx-updates-meta__muted">Not checked yet</span>
					<?php endif; ?>
				</dd>
			</div>
			<?php if ( ! empty( $status['release_url'] ) ) : ?>
			<div class="pdx-updates-meta__row">
				<dt>Release</dt>
				<dd><a href="<?php echo esc_url( $status['release_url'] ); ?>" target="_blank" rel="noopener noreferrer">View on GitHub</a></dd>
			</div>
			<?php endif; ?>
		</dl>

		<?php if ( ! empty( $status['error'] ) ) : ?>
		<div class="pdx-updates-notice pdx-updates-notice--error">
			<?php echo esc_html( $status['error'] ); ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $status['maintenance_active'] ) ) : ?>
		<div class="pdx-updates-notice pdx-updates-notice--warn">
			<strong>Maintenance mode is active.</strong>
			<?php if ( ! empty( $status['maintenance_stale'] ) ) : ?>
				The <code>.maintenance</code> file looks stale and may be left over from a failed update.
			<?php else : ?>
				An update may still be running. If the site is stuck, clear maintenance mode below.
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<div class="pdx-updates-actions">
			<a href="<?php echo esc_url( $check_url ); ?>" class="pdx-btn-primary">Check for Updates</a>
			<?php if ( ! empty( $status['update_available'] ) ) : ?>
				<a href="<?php echo esc_url( $plugins ); ?>" class="pdx-btn-ghost">Open Plugins screen</a>
			<?php endif; ?>
			<?php if ( ! empty( $status['maintenance_active'] ) ) : ?>
				<a href="<?php echo esc_url( $maint_url ); ?>" class="pdx-btn-ghost">Clear maintenance mode</a>
			<?php endif; ?>
		</div>
	</div>
</section>
