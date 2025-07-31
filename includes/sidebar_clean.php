<?php
require_once __DIR__.'/../config/config.php';
global $conn;
//if (session_status() === PHP_SESSION_NONE) session_start();

// Super admin detection
$is_super_admin = isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3;

// Fetch menu items from DB
$stmt = $conn->prepare("SELECT * FROM menu_items WHERE is_active = 1 ORDER BY menu_group, sort_order");
$stmt->execute();
$result = $stmt->get_result();
$menu = [];
while ($row = $result->fetch_assoc()) {
    $menu[$row['menu_group']][] = $row;
}
$stmt->close();

// Get user permissions
$user_permissions = $is_super_admin ? null : ($_SESSION['permissions'] ?? []);

// Branding: logo, name, address
$logo_path = BASE_URL . '/uploads/logo.png';
$church_name = 'FMC-KM';
$church_address = '';
if (isset($_SESSION['church_id'])) {
    $stmt = $conn->prepare('SELECT name, address, logo FROM churches WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $_SESSION['church_id']);
    $stmt->execute();
    $stmt->bind_result($db_name, $db_address, $db_logo);
    if ($stmt->fetch()) {
        if ($db_logo && file_exists(__DIR__.'/../uploads/'.$db_logo)) {
            $logo_path = BASE_URL.'/uploads/'.rawurlencode($db_logo);
        }
        if ($db_name) $church_name = $db_name;
        if ($db_address) $church_address = $db_address;
    }
    $stmt->close();
}

// Current URL for active state
$current_url = $_SERVER['REQUEST_URI'] ?? '';
?>
<style>
  .main-sidebar {
    background: linear-gradient(180deg, #23272b 0%, #343a40 100%);
    box-shadow: 2px 0 8px rgba(0,0,0,0.07);
    transition: transform 0.3s;
  }
  .sidebar-brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1.1rem 0.5rem 0.7rem 0.5rem;
    background: #1a1d20;
    border-bottom: 1px solid #222;
    margin-bottom: 0.5em;
  }
  .sidebar-brand img {
    max-height: 48px;
    margin-bottom: 0.4em;
    border-radius: 7px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    background: #fff;
    padding: 2px;
  }
  .sidebar-brand .church-name {
    color: #fff;
    font-size: 1.15em;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-align: center;
    margin-bottom: 0.15em;
    line-height: 1.2;
    word-break: break-word;
  }
  .sidebar-brand .church-address {
    color: #b0b7be;
    font-size: 0.98em;
    font-weight: 400;
    text-align: center;
    opacity: 0.85;
    margin-bottom: 0.1em;
    line-height: 1.15;
    word-break: break-word;
  }
  .sidebar-heading {
    color: #b0b7be;
    font-size: 1em;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-top: 1.2em;
    margin-bottom: 0.6em;
    padding: 0.5em 0.9em;
    opacity: 0.85;
    cursor: pointer;
    user-select: none;
    transition: background 0.2s, color 0.2s;
    border-radius: 4px;
    margin-left: 0.4em;
    margin-right: 0.4em;
    position: relative;
  }
  .sidebar-heading:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
  }
  .sidebar-heading::after {
    content: '\f107';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    right: 0.7em;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.3s ease;
  }
  .sidebar-heading.collapsed::after {
    transform: translateY(-50%) rotate(-90deg);
  }
  .menu-group {
    overflow: hidden;
    transition: max-height 0.3s ease-out;
  }
  .menu-group.collapsed {
    max-height: 0 !important;
  }
  .nav-sidebar .nav-link {
    color: #d1d5db;
    border-radius: 6px;
    margin: 0.1em 0.4em;
    transition: background 0.18s, color 0.18s;
    font-size: 1.04em;
  }
  .nav-sidebar .nav-link.active, .nav-sidebar .nav-link:active {
    background: #495464;
    color: #fff;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  }
  .nav-sidebar .nav-link:hover {
    background: #3b4148;
    color: #fff;
  }
  .nav-sidebar .nav-icon {
    font-size: 1.16em;
    margin-right: 0.7em;
    opacity: 0.92;
  }
  .nav-sidebar .nav-item {
    margin-bottom: 0.1em;
  }
</style>
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <div class="sidebar-brand">
    <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Logo" onerror="this.style.display='none'">
    <div class="church-name"><?php echo htmlspecialchars($church_name); ?></div>
    <?php if ($church_address): ?>
      <div class="church-address"><?php echo htmlspecialchars($church_address); ?></div>
    <?php endif; ?>
  </div>
  <div class="sidebar">
    <?php foreach ($menu as $group => $items): ?>
      <?php
      $visible_items = [];
      foreach ($items as $item) {
        if ($is_super_admin || (is_array($user_permissions) && in_array($item['permission_name'], $user_permissions))) {
          $visible_items[] = $item;
        }
      }
      if (empty($visible_items)) continue;
      
      // Generate unique ID for this group
      $group_id = 'menu-group-' . preg_replace('/[^a-zA-Z0-9]/', '-', strtolower($group));
      ?>
      <div class="sidebar-heading" onclick="toggleMenuGroup('<?php echo $group_id; ?>', this)">
        <?php echo htmlspecialchars($group); ?>
      </div>
      <div class="menu-group" id="<?php echo $group_id; ?>">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
          <?php foreach ($visible_items as $item): ?>
            <?php $is_active = strpos($current_url, basename($item['url'])) !== false; ?>
            <li class="nav-item">
              <a href="<?php echo BASE_URL . '/' . $item['url']; ?>" class="nav-link<?php echo $is_active ? ' active' : ''; ?>">
                <i class="nav-icon <?php echo htmlspecialchars($item['icon']); ?>"></i>
                <p><?php echo htmlspecialchars($item['label']); ?></p>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
</aside>

<script>
function toggleMenuGroup(groupId, headerElement) {
  const menuGroup = document.getElementById(groupId);
  const isCollapsed = headerElement.classList.contains('collapsed');
  
  if (isCollapsed) {
    // Expand
    headerElement.classList.remove('collapsed');
    menuGroup.style.maxHeight = menuGroup.scrollHeight + 'px';
    menuGroup.classList.remove('collapsed');
    
    // Store expanded state
    localStorage.setItem('menu-' + groupId, 'expanded');
  } else {
    // Collapse
    headerElement.classList.add('collapsed');
    menuGroup.style.maxHeight = '0px';
    menuGroup.classList.add('collapsed');
    
    // Store collapsed state
    localStorage.setItem('menu-' + groupId, 'collapsed');
  }
}

// Restore menu states on page load
document.addEventListener('DOMContentLoaded', function() {
  const menuGroups = document.querySelectorAll('.menu-group');
  
  menuGroups.forEach(function(group) {
    const groupId = group.id;
    const header = group.previousElementSibling;
    const savedState = localStorage.getItem('menu-' + groupId);
    
    // Set initial max-height for smooth transitions
    group.style.maxHeight = group.scrollHeight + 'px';
    
    // Apply saved state or default to expanded
    if (savedState === 'collapsed') {
      header.classList.add('collapsed');
      group.style.maxHeight = '0px';
      group.classList.add('collapsed');
    }
  });
});
</script>
