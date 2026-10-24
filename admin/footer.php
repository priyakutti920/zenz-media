 </main>
 <!-- Footer -->
 <footer class="admin-footer">
 <div class="footer-content">
 <p class="footer-text">
 &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($adminBrandName ?? 'Cloud Aspire'); ?> Admin Panel. All rights reserved.
 </p>
 <div class="footer-links">
 <span class="footer-divider">|</span>
 <a href="dashboard">Dashboard</a>
 <a href="employee">Employees</a>
 <a href="attendance">Attendance</a>
 <a href="task">Tasks</a>
 <a href="project">Projects</a>
 <a href="reports">Reports</a>
 <a href="site_settings">Site Settings</a>
 <a href="settings">Settings</a>
 </div>
 </div>
 </footer>

 <style>
 /* Footer */
 .admin-footer {
 background: var(--surface);
 border-top: 1px solid var(--border);
 padding: 24px 32px;
 margin-top: auto;
 width: 100%;
 box-sizing: border-box;
 transition: all var(--transition-smooth);
 }

 .footer-content {
 max-width: 1440px;
 width: 100%;
 margin: 0 auto;
 display: flex;
 justify-content: space-between;
 align-items: center;
 flex-wrap: wrap;
 gap: 16px;
 box-sizing: border-box;
 }

 .footer-text {
 margin: 0;
 font-size: 14px;
 color: var(--text-tertiary);
 }

 .footer-links {
 display: flex;
 align-items: center;
 gap: 12px;
 }

 .footer-links a {
 font-size: 14px;
 color: var(--text-secondary);
 text-decoration: none;
 transition: color var(--transition);
 }

 .footer-links a:hover {
 color: var(--primary-500);
 }

 .footer-divider {
 color: var(--text-tertiary);
 }

 @media (max-width: 960px) {
 .admin-footer {
 padding: 20px 16px;
 }

 .footer-content {
 flex-direction: column;
 text-align: center;
 }

 .footer-links {
 flex-wrap: wrap;
 justify-content: center;
 }
 }

 @media (max-width: 480px) {
 .admin-footer {
 padding: 16px 12px;
 }
 }
 </style>

 </body>
 </html>
