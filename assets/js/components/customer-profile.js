/**
 * Customer Profile Card Component
 * 
 * Shared component for displaying customer profile from LINE/Facebook
 * Used in: orders.php, payment-history.php, savings.php, installments.php, addresses.php
 */

/**
 * Generate HTML for customer profile badge (compact version)
 * @param {Object} profile - { platform, name, avatar, phone, email }
 * @returns {string} HTML string
 */
function renderCustomerProfileBadge(profile) {
    if (!profile || !profile.name) {
        return `<span class="customer-badge customer-badge-unknown">
            <span class="badge-avatar">👤</span>
            <span class="badge-name">ไม่ระบุลูกค้า</span>
        </span>`;
    }
    
    const platform = profile.platform || 'web';
    const platformIcon = getPlatformIcon(platform);
    const platformClass = `platform-${platform}`;
    
    // Validate avatar URL before using it
    const validAvatar = validateAvatarUrl(profile.avatar);
    
    const avatarHtml = validAvatar 
        ? `<img src="${validAvatar}" alt="${profile.name}" class="badge-avatar-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
           <span class="badge-avatar-fallback" style="display:none;">${getInitials(profile.name)}</span>`
        : `<span class="badge-avatar-fallback">${getInitials(profile.name)}</span>`;
    
    return `
        <div class="customer-badge ${platformClass}">
            <div class="badge-avatar-container">
                ${avatarHtml}
                <span class="badge-platform-icon" title="${getPlatformName(platform)}">${platformIcon}</span>
            </div>
            <div class="badge-info">
                <span class="badge-name">${profile.name}</span>
                ${profile.phone ? `<span class="badge-phone">${profile.phone}</span>` : ''}
            </div>
        </div>
    `;
}

/**
 * Generate HTML for customer profile card (full version for modal)
 * @param {Object} profile - { platform, platform_id, name, avatar, phone, email, status }
 * @returns {string} HTML string
 */
function renderCustomerProfileCard(profile) {
    if (!profile) {
        return `<div class="customer-profile-empty">
            <span class="empty-icon">👤</span>
            <span class="empty-text">ไม่พบข้อมูลลูกค้า</span>
        </div>`;
    }
    
    const platform = profile.platform || 'web';
    const platformIcon = getPlatformIcon(platform);
    const platformClass = `customer-profile-card platform-${platform}`;
    
    // Validate avatar URL before using it
    const validAvatar = validateAvatarUrl(profile.avatar);
    
    const avatarHtml = validAvatar 
        ? `<img src="${validAvatar}" alt="${profile.name}" class="profile-avatar-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
           <div class="profile-avatar-fallback" style="display:none;">${getInitials(profile.name || 'U')}</div>`
        : `<div class="profile-avatar-fallback">${getInitials(profile.name || 'U')}</div>`;
    
    return `
        <div class="${platformClass}">
            <div class="profile-header">
                <div class="profile-avatar">
                    ${avatarHtml}
                </div>
                <div class="profile-info">
                    <h4 class="profile-name">${profile.name || 'ไม่ระบุชื่อ'}</h4>
                    ${profile.phone ? `<p class="profile-phone"><i class="fas fa-phone"></i> ${profile.phone}</p>` : ''}
                    ${profile.email ? `<p class="profile-email"><i class="fas fa-envelope"></i> ${profile.email}</p>` : ''}
                    <div class="profile-platform">
                        <span class="platform-badge ${platform}">${platformIcon} ${getPlatformName(platform)}</span>
                        ${profile.status ? `<span class="profile-status">${profile.status}</span>` : ''}
                    </div>
                </div>
            </div>
            ${profile.platform_id ? `<div class="profile-id"><small>ID: ${profile.platform_id}</small></div>` : ''}
        </div>
    `;
}

/**
 * Get platform icon (SVG)
 */
function getPlatformIcon(platform) {
    const icons = {
        'line': '<svg class="platform-svg-icon line-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63.349 0 .631.285.631.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.349 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>',
        'facebook': '<svg class="platform-svg-icon fb-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'instagram': '<svg class="platform-svg-icon ig-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.757-6.162-6.162-6.162zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439z"/></svg>',
        'web': '<svg class="platform-svg-icon web-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm-.5 2.05c-.865 1.68-1.507 3.456-1.907 5.295H5.48A9.94 9.94 0 0 1 11.5 2.05zm-7.41 7.295h4.923c-.11 1.14-.17 2.3-.17 3.48 0 1.18.06 2.34.17 3.48H4.09a9.88 9.88 0 0 1 0-6.96zm1.39 8.96h4.113c.4 1.839 1.042 3.615 1.907 5.295a9.94 9.94 0 0 1-6.02-5.295zM12 22c-1.14-1.68-2.01-3.52-2.59-5.445h5.18C14.01 18.48 13.14 20.32 12 22zm2.77-7.175H9.23c-.12-1.14-.18-2.3-.18-3.48s.06-2.34.18-3.48h5.54c.12 1.14.18 2.3.18 3.48s-.06 2.34-.18 3.48zm.73 7.23c.865-1.68 1.507-3.456 1.907-5.295h4.113a9.94 9.94 0 0 1-6.02 5.295zm2.09-7.23c.11-1.14.17-2.3.17-3.48 0-1.18-.06-2.34-.17-3.48h4.923a9.88 9.88 0 0 1 0 6.96h-4.923zm1.93-8.96h-4.113c-.4-1.839-1.042-3.615-1.907-5.295a9.94 9.94 0 0 1 6.02 5.295z"/></svg>'
    };
    return icons[platform] || '<svg class="platform-svg-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
}

/**
 * Get platform display name
 */
function getPlatformName(platform) {
    const names = {
        'line': 'LINE',
        'facebook': 'Facebook',
        'instagram': 'Instagram',
        'web': 'Web'
    };
    return names[platform] || platform;
}

/**
 * Get initials from name
 */
function getInitials(name) {
    if (!name) return '?';
    return name.split(' ')
        .map(n => n.charAt(0))
        .join('')
        .substring(0, 2)
        .toUpperCase();
}

/**
 * Validate avatar URL - filter out bad/placeholder URLs
 * @param {string} url - Avatar URL to validate
 * @returns {string|null} - Valid URL or null
 */
function validateAvatarUrl(url) {
    if (!url) return null;
    
    // List of invalid/placeholder avatar patterns
    const invalidPatterns = [
        'default_avatar',
        'placeholder',
        'no-image',
        'no_image',
        'avatar-default'
    ];
    
    // Check if URL contains invalid patterns
    const urlLower = url.toLowerCase();
    for (const pattern of invalidPatterns) {
        if (urlLower.includes(pattern)) {
            return null;
        }
    }
    
    // Check if URL is a valid URL format (starts with http/https or /)
    if (!url.startsWith('http://') && !url.startsWith('https://') && !url.startsWith('/')) {
        // Might be a malformed URL or just a filename
        return null;
    }
    
    // Check for excessively long URLs that might be malformed (> 500 chars)
    if (url.length > 500) {
        return null;
    }
    
    return url;
}

/**
 * Parse customer profile from different data sources
 */
function parseCustomerProfile(data, source = 'order') {
    if (!data) return null;
    
    // Try different field names based on source
    let profile = {
        platform: data.customer_platform || data.platform || null,
        platform_id: data.customer_platform_id || data.platform_user_id || data.external_user_id || null,
        name: data.customer_name || data.platform_user_name || data.full_name || null,
        avatar: data.customer_avatar || data.platform_user_avatar || null,
        phone: data.customer_phone || data.phone || null,
        email: data.customer_email || data.email || null,
        status: data.platform_user_status || null
    };
    
    // Parse metadata if exists
    if (data.metadata) {
        try {
            const meta = typeof data.metadata === 'string' ? JSON.parse(data.metadata) : data.metadata;
            if (meta.phone && !profile.phone) profile.phone = meta.phone;
            if (meta.email && !profile.email) profile.email = meta.email;
        } catch (e) {
            // ignore parse errors
        }
    }
    
    return profile;
}

// Export functions for use in other scripts
if (typeof window !== 'undefined') {
    window.CustomerProfile = {
        renderBadge: renderCustomerProfileBadge,
        renderCard: renderCustomerProfileCard,
        parse: parseCustomerProfile,
        getPlatformIcon,
        getPlatformName,
        getInitials,
        validateAvatarUrl
    };
}
