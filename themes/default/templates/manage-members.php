<?php /* Everyone in the forum. Moderators can mute, the owner can also change roles and remove people. Vars: $members, $me, $owner, $csrf. */ ?>
<section class="lf-card lf-table-card">
  <div class="lf-scroll"><table class="lf-table">
    <thead><tr><th>Member</th><th>Role</th><th>Joined</th><th>Last seen</th><th><span class="lf-sr">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($members as $m):
        $mid = (int) $m['id'];
        $isMe = $mid === (int) $me['id'];
    ?>
      <tr>
        <td>
          <b><?= $m['username'] !== null ? h($m['username']) : '<i>choosing a username</i>' ?></b>
          <?php if ($m['status'] === 'muted'): ?><span class="lf-chip lf-chip-flag">Muted</span><?php endif; ?>
          <?php if ($isMe): ?><span class="lf-hint">(you)</span><?php endif; ?>
        </td>
        <td><?= $m['role'] === 'owner' ? 'Owner' : ($m['role'] === 'moderator' ? 'Moderator' : 'Member') ?></td>
        <td><time datetime="<?= h(lf_iso($m['created_at'])) ?>"><?= h(lf_ago($m['created_at'])) ?></time></td>
        <td><?= $m['last_seen_at'] !== null ? '<time datetime="' . h(lf_iso($m['last_seen_at'])) . '">' . h(lf_ago($m['last_seen_at'])) . '</time>' : '<span class="lf-hint">never</span>' ?></td>
        <td>
          <div class="lf-actions">
          <?php if (!$isMe && $m['role'] !== 'owner'): ?>
            <?php if ($m['role'] === 'member'): ?>
              <?= lf_form("manage/members/$mid/" . ($m['status'] === 'muted' ? 'unmute' : 'mute'), $csrf, 'lf-inline', $m['status'] === 'muted' ? '' : 'Mute this member? They can still read, but not write.') ?><button class="lf-btn lf-btn-small" type="submit"><?= $m['status'] === 'muted' ? 'Unmute' : 'Mute' ?></button></form>
            <?php endif; ?>
            <?php if ($owner): ?>
              <?= lf_form("manage/members/$mid/" . ($m['role'] === 'moderator' ? 'member' : 'moderator'), $csrf, 'lf-inline') ?><button class="lf-btn lf-btn-small" type="submit"><?= $m['role'] === 'moderator' ? 'Make member' : 'Make moderator' ?></button></form>
              <?= lf_form("manage/members/$mid/remove", $csrf, 'lf-inline', 'Remove this member? What they wrote stays, shown as Former member.') ?><button class="lf-btn lf-btn-small lf-btn-danger" type="submit">Remove</button></form>
            <?php endif; ?>
          <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
