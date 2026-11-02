/**
 * HTML Sanitization Utility
 *
 * Provides safe HTML sanitization using DOMPurify to prevent XSS attacks.
 *
 * @since 4.7.0
 */

import DOMPurify, { Config } from 'dompurify';

/**
 * Sanitize HTML content to prevent XSS attacks.
 *
 * @param {string} html - The HTML string to sanitize.
 * @param {Config} config - Optional DOMPurify configuration.
 * @returns {string} Sanitized HTML string safe for use with dangerouslySetInnerHTML.
 */
export function sanitizeHtml(html: string, config?: Config): string {
  if (typeof window === 'undefined') {
    // Server-side rendering fallback - comprehensive XSS sanitization
    let sanitized = html;

    // Remove script tags and their content
    sanitized = sanitized.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');

    // Remove event handler attributes (onerror, onclick, onmouseover, etc.)
    sanitized = sanitized.replace(/\s*on\w+\s*=\s*["'][^"']*["']/gi, '');
    sanitized = sanitized.replace(/\s*on\w+\s*=\s*[^\s>]*/gi, '');

    // Remove javascript: URLs from href and src attributes
    sanitized = sanitized.replace(/\s*(href|src|xlink:href)\s*=\s*["']?\s*javascript:/gi, '');

    // Remove data: URLs that could contain scripts (allow only safe image types)
    sanitized = sanitized.replace(
      /\s*(href|src|xlink:href)\s*=\s*["']?\s*data:(?!image\/(png|jpeg|gif|webp|svg\+xml))/gi,
      '',
    );

    // Remove style attributes that could contain javascript: or expression()
    sanitized = sanitized.replace(/\s*style\s*=\s*["'][^"']*expression\s*\([^"']*["']/gi, '');
    sanitized = sanitized.replace(/\s*style\s*=\s*["'][^"']*javascript:[^"']*["']/gi, '');

    // Remove iframe and embed tags (commonly used for XSS)
    sanitized = sanitized.replace(/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/gi, '');
    sanitized = sanitized.replace(/<embed\b[^<]*(?:(?!<\/embed>)<[^<]*)*<\/embed>/gi, '');
    sanitized = sanitized.replace(/<object\b[^<]*(?:(?!<\/object>)<[^<]*)*<\/object>/gi, '');

    // Remove foreignObject and use tags from SVG (can be used for XSS)
    sanitized = sanitized.replace(/<foreignObject\b[^<]*(?:(?!<\/foreignObject>)<[^<]*)*<\/foreignObject>/gi, '');
    sanitized = sanitized.replace(/<use\b[^<]*(?:(?!<\/use>)<[^<]*)*<\/use>/gi, '');

    return sanitized;
  }

  // Client-side sanitization with DOMPurify
  // Use DOMPurify's default safe tag and attribute lists, which includes:
  // - Standard HTML elements (including table elements: table, tbody, td, th, thead, tfoot, tr)
  // - SVG elements and attributes
  // - Safe attributes for all elements
  // Only restrict data attributes for security
  return DOMPurify.sanitize(html, {
    ALLOW_DATA_ATTR: false,
    ...config,
  });
}
