<?php
$pageTitle = 'Trade Overview';
require_once __DIR__ . '/../views/includes/admin-header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-graph-up"></i> Trade Overview
    </h1>
    <div>
        <button class="btn btn-sm btn-outline-primary" onclick="refreshTrades()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<div class="card card-dark">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-dark-custom table-hover" id="tradesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Trade ID</th>
                        <th>Asset</th>
                        <th>Direction</th>
                        <th>Stake</th>
                        <th>Profit</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody id="tradesTableBody">
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <nav aria-label="Trades pagination">
            <ul class="pagination justify-content-center" id="pagination">
                <!-- Pagination will be loaded here -->
            </ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 1;
let tradesInterval;

async function loadTrades(page = 1) {
    try {
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        const data = await apiCall(`${apiBase}/admin.php?action=trades&page=${page}&limit=50`);
        
        const tbody = document.getElementById('tradesTableBody');
        
        if (data.trades.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4">No trades found</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.trades.map(trade => `
            <tr>
                <td>${trade.id}</td>
                <td>${escapeHtml(trade.user_email)}</td>
                <td><code>${trade.trade_id}</code></td>
                <td>${trade.asset || 'N/A'}</td>
                <td>
                    <span class="badge ${trade.direction === 'RISE' ? 'bg-success' : 'bg-danger'}">
                        ${trade.direction}
                    </span>
                </td>
                <td>$${trade.stake.toFixed(2)}</td>
                <td class="${trade.profit >= 0 ? 'text-success' : 'text-danger'}">
                    ${trade.profit >= 0 ? '+' : ''}$${trade.profit.toFixed(2)}
                </td>
                <td>
                    <span class="badge ${
                        trade.status === 'won' ? 'bg-success' :
                        trade.status === 'lost' ? 'bg-danger' :
                        trade.status === 'pending' ? 'bg-warning' :
                        'bg-secondary'
                    }">
                        ${trade.status.toUpperCase()}
                    </span>
                </td>
                <td>${new Date(trade.timestamp).toLocaleString()}</td>
            </tr>
        `).join('');
        
        // Update pagination
        updatePagination(data.pagination);
        
    } catch (error) {
        console.error('Error loading trades:', error);
        document.getElementById('tradesTableBody').innerHTML = 
            '<tr><td colspan="9" class="text-center text-danger py-4">Failed to load trades</td></tr>';
    }
}

function updatePagination(pagination) {
    const paginationEl = document.getElementById('pagination');
    let html = '';
    
    if (pagination.pages > 1) {
        html += `<li class="page-item ${pagination.page === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadTrades(${pagination.page - 1}); return false;">Previous</a>
        </li>`;
        
        for (let i = 1; i <= pagination.pages; i++) {
            if (i === 1 || i === pagination.pages || (i >= pagination.page - 2 && i <= pagination.page + 2)) {
                html += `<li class="page-item ${i === pagination.page ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="loadTrades(${i}); return false;">${i}</a>
                </li>`;
            } else if (i === pagination.page - 3 || i === pagination.page + 3) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }
        
        html += `<li class="page-item ${pagination.page === pagination.pages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadTrades(${pagination.page + 1}); return false;">Next</a>
        </li>`;
    }
    
    paginationEl.innerHTML = html;
}

function refreshTrades() {
    loadTrades(currentPage);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load initial data
loadTrades();

// Auto-refresh every 30 seconds
tradesInterval = setInterval(() => {
    loadTrades(currentPage);
}, 30000);

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (tradesInterval) {
        clearInterval(tradesInterval);
    }
});
</script>

<?php
$adminScripts = [];
require_once __DIR__ . '/../views/includes/admin-footer.php';
?>

