<?php
$pageTitle = 'User Management';
require_once __DIR__ . '/../../views/includes/admin-header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-people"></i> User Management
    </h1>
    <div>
        <input type="text" class="form-control form-control-dark d-inline-block" id="searchInput" 
               placeholder="Search users..." style="width: 250px;" onkeyup="searchUsers()">
    </div>
</div>

<div class="card card-dark">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-dark-custom table-hover" id="usersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>API Token</th>
                        <th>Bot Active</th>
                        <th>Total Trades</th>
                        <th>Win Rate</th>
                        <th>Net Profit</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <nav aria-label="Users pagination">
            <ul class="pagination justify-content-center" id="pagination">
                <!-- Pagination will be loaded here -->
            </ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 1;
let currentSearch = '';

async function loadUsers(page = 1, search = '') {
    try {
        const url = `/api/admin.php?action=users&page=${page}&limit=20${search ? '&search=' + encodeURIComponent(search) : ''}`;
        const data = await apiCall(url);
        
        const tbody = document.getElementById('usersTableBody');
        
        if (data.users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4">No users found</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.users.map(user => `
            <tr>
                <td>${user.id}</td>
                <td>${escapeHtml(user.email)}</td>
                <td>
                    <span class="badge ${user.is_active ? 'bg-success' : 'bg-danger'}">
                        ${user.is_active ? 'Active' : 'Suspended'}
                    </span>
                </td>
                <td>
                    <span class="badge ${user.has_api_token ? 'bg-success' : 'bg-warning'}">
                        ${user.has_api_token ? 'Connected' : 'Not Connected'}
                    </span>
                </td>
                <td>
                    <span class="badge ${user.settings?.is_bot_active ? 'bg-success' : 'bg-secondary'}">
                        ${user.settings?.is_bot_active ? 'Active' : 'Inactive'}
                    </span>
                </td>
                <td>${user.stats.total_trades}</td>
                <td>${user.stats.win_rate}%</td>
                <td class="${parseFloat(user.stats.net_profit.replace(/,/g, '')) >= 0 ? 'text-success' : 'text-danger'}">
                    $${user.stats.net_profit}
                </td>
                <td>${new Date(user.created_at).toLocaleDateString()}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        ${user.is_active ? 
                            `<button class="btn btn-outline-warning" onclick="suspendUser(${user.id})" title="Suspend">
                                <i class="bi bi-pause-circle"></i>
                            </button>` :
                            `<button class="btn btn-outline-success" onclick="activateUser(${user.id})" title="Activate">
                                <i class="bi bi-play-circle"></i>
                            </button>`
                        }
                        <button class="btn btn-outline-danger" onclick="deleteUser(${user.id})" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
        
        // Update pagination
        updatePagination(data.pagination);
        
    } catch (error) {
        console.error('Error loading users:', error);
        document.getElementById('usersTableBody').innerHTML = 
            '<tr><td colspan="10" class="text-center text-danger py-4">Failed to load users</td></tr>';
    }
}

function updatePagination(pagination) {
    const paginationEl = document.getElementById('pagination');
    let html = '';
    
    if (pagination.pages > 1) {
        // Previous button
        html += `<li class="page-item ${pagination.page === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadUsers(${pagination.page - 1}, '${currentSearch}'); return false;">Previous</a>
        </li>`;
        
        // Page numbers
        for (let i = 1; i <= pagination.pages; i++) {
            if (i === 1 || i === pagination.pages || (i >= pagination.page - 2 && i <= pagination.page + 2)) {
                html += `<li class="page-item ${i === pagination.page ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="loadUsers(${i}, '${currentSearch}'); return false;">${i}</a>
                </li>`;
            } else if (i === pagination.page - 3 || i === pagination.page + 3) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }
        
        // Next button
        html += `<li class="page-item ${pagination.page === pagination.pages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadUsers(${pagination.page + 1}, '${currentSearch}'); return false;">Next</a>
        </li>`;
    }
    
    paginationEl.innerHTML = html;
}

function searchUsers() {
    const search = document.getElementById('searchInput').value;
    currentSearch = search;
    currentPage = 1;
    loadUsers(1, search);
}

async function suspendUser(userId) {
    if (!confirm('Are you sure you want to suspend this user?')) return;
    
    try {
        await apiCall('/api/admin.php?action=user-suspend', {
            method: 'POST',
            body: { user_id: userId }
        });
        alert('User suspended successfully');
        loadUsers(currentPage, currentSearch);
    } catch (error) {
        alert('Failed to suspend user: ' + error.message);
    }
}

async function activateUser(userId) {
    if (!confirm('Are you sure you want to activate this user?')) return;
    
    try {
        await apiCall('/api/admin.php?action=user-activate', {
            method: 'POST',
            body: { user_id: userId }
        });
        alert('User activated successfully');
        loadUsers(currentPage, currentSearch);
    } catch (error) {
        alert('Failed to activate user: ' + error.message);
    }
}

async function deleteUser(userId) {
    if (!confirm('Are you sure you want to DELETE this user? This action cannot be undone!')) return;
    
    if (!confirm('This will permanently delete the user and all their data. Are you absolutely sure?')) return;
    
    try {
        await apiCall('/api/admin.php?action=user-delete', {
            method: 'POST',
            body: { user_id: userId }
        });
        alert('User deleted successfully');
        loadUsers(currentPage, currentSearch);
    } catch (error) {
        alert('Failed to delete user: ' + error.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load initial data
loadUsers();
</script>

<?php
$adminScripts = [];
require_once __DIR__ . '/../../views/includes/admin-footer.php';
?>

