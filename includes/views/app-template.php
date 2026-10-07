<?php
/**
 * Markup for the WowRestro app, carrying the design's own template directives.
 *
 * Interpreted by assets/js/wowrestro-app.js. Table tags stay as sc-raw-* so the
 * HTML parser cannot foster-parent a directive out of a table at parse time.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;
?>
<div style="min-height:100vh;background:#f6f8f9;font-family:Inter,sans-serif;">

  <?php /* The real WordPress admin bar sits above this screen. */ ?>

  <div style="display:flex;align-items:stretch;">

    <?php /* The real WordPress admin menu sits beside this screen. */ ?>

    <div class="wcf-app-shell" style="flex:1;min-width:0;display:flex;align-items:stretch;">

      <aside class="wcf-scroll wcf-primary-nav" style="flex:0 0 72px;width:72px;background:#f6f6f6;border-right:1px solid #e5e5e5;box-shadow:0 1px 2px rgba(0,0,0,.05);position:sticky;top:0;height:100vh;display:flex;flex-direction:column;">
        <div style="display:flex;align-items:center;justify-content:center;padding:12px;min-height:78px;">
		  <span title="{{ brandName }}" style="width:44px;height:44px;padding:3px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;display:grid;place-items:center;background:#fff;"><img src="{{ brandLogo }}" alt="{{ brandName }}" style="width:100%;height:100%;object-fit:contain;display:block;border-radius:8px;"></span>
        </div>
        <nav class="wcf-scroll" style="flex:1;display:flex;flex-direction:column;gap:4px;padding:16px 0;overflow-y:auto;">
          <sc-for list="{{ railItems }}" as="rail">
            <button type="button" sc-camel-on-click="{{ rail.onClick }}" style="display:flex;width:calc(100% - 8px);border:0;font:inherit;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:12px 4px;margin:0 4px;border-radius:6px;cursor:pointer;transition:all .2s;background:{{ rail.bg }};color:{{ rail.color }};">
              <i class="{{ rail.icon }}" style="font-size:22px;"></i>
              <span style="font-size:10px;font-weight:500;line-height:1.25;text-align:center;">{{ rail.title }}</span>
            </button>
          </sc-for>
        </nav>
        <div style="padding:8px;display:flex;flex-direction:column;align-items:center;gap:6px;border-top:1px solid rgba(229,229,229,.5);">
          <button class="wcf-button wcf-button--icon" type="button" sc-camel-on-click="{{ goAbout }}" title="About Us" aria-label="About WowRestro" style="width:36px;height:36px;border-radius:8px;cursor:pointer;"><i class="ph ph-info" style="font-size:18px;"></i></button>
          <a href="<?php echo esc_url( admin_url() ); ?>" title="<?php esc_attr_e( 'Return to WordPress', 'wowrestro' ); ?>" style="width:36px;height:36px;display:grid;place-items:center;border-radius:50%;background:#eff6ff;border:1px solid #2B4BFF;color:#2B4BFF;cursor:pointer;"><i class="ph ph-house" style="font-size:19px;"></i></a>
        </div>
      </aside>

      <sc-if value="{{ hasSecondary }}">
        <aside class="wcf-scroll wcf-secondary-nav" style="flex:0 0 240px;width:240px;background:#fff;border-right:1px solid #e5e5e5;box-shadow:0 1px 2px rgba(0,0,0,.05);position:sticky;top:0;height:100vh;display:flex;flex-direction:column;">
          <span style="font-size:14px;padding:0 12px;margin-top:0;color:#252525;">{{ secondaryTitle }}</span>
          <nav class="wcf-scroll wcf-secondary-menu" style="flex:1;display:flex;flex-direction:column;gap:4px;padding:16px 12px;overflow-y:auto;">
            <sc-for list="{{ secondaryItems }}" as="nav">
              <div>
                <sc-if value="{{ nav.firstSection }}">
                  <div style="padding:0 6px 4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.025em;color:rgba(37,37,37,.5);">{{ nav.section }}</div>
                </sc-if>
                <sc-if value="{{ nav.laterSection }}">
                  <div style="padding:12px 6px 4px;margin-top:8px;border-top:1px solid rgba(229,229,229,.6);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.025em;color:rgba(37,37,37,.5);">{{ nav.section }}</div>
                </sc-if>
                <button type="button" sc-camel-on-click="{{ nav.onClick }}" style="display:flex;width:100%;border:0;font:inherit;text-align:left;align-items:center;gap:12px;padding:12px 6px;border-radius:6px;cursor:pointer;transition:all .2s;background:{{ nav.bg }};color:{{ nav.color }};">
                  <i class="{{ nav.icon }}" style="font-size:16px;flex-shrink:0;"></i>
                  <span style="font-size:14px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ nav.title }}</span>
                  <sc-if value="{{ nav.isPro }}">
                    <span style="display:inline-flex;align-items:center;height:18px;padding:0 6px;border-radius:999px;background:#E9EDFF;color:#2B4BFF;font-size:9px;font-weight:700;letter-spacing:.05em;">PRO</span>
                  </sc-if>
                  <sc-if value="{{ nav.hasChildren }}">
                    <i class="{{ nav.caret }}" style="font-size:13px;color:#a5a9be;flex-shrink:0;"></i>
                  </sc-if>
                  <sc-if value="{{ nav.showDot }}">
                    <span style="width:8px;height:8px;border-radius:50%;background:#2B4BFF;flex-shrink:0;"></span>
                  </sc-if>
                </button>
                <sc-if value="{{ nav.expanded }}">
                  <div style="display:flex;flex-direction:column;gap:2px;margin:2px 0 8px 15px;padding-left:11px;border-left:1px solid #ececec;">
                    <sc-for list="{{ nav.children }}" as="sub">
                      <button type="button" sc-camel-on-click="{{ sub.onClick }}" style="display:flex;width:100%;border:0;font:inherit;text-align:left;align-items:center;gap:10px;padding:9px 8px;border-radius:6px;cursor:pointer;transition:all .2s;background:{{ sub.bg }};color:{{ sub.color }};" style-hover="background:#f8f8f8;">
                        <i class="{{ sub.icon }}" style="font-size:15px;flex-shrink:0;"></i>
                        <span style="font-size:13px;font-weight:{{ sub.weight }};flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ sub.title }}</span>
                        <sc-if value="{{ sub.hasCount }}">
                          <span style="display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 6px;border-radius:999px;background:#f1f1f4;color:#525266;font-size:10px;font-weight:600;">{{ sub.count }}</span>
                        </sc-if>
                      </button>
                    </sc-for>
		        </div>
	        </div>
		        </sc-if>
            </sc-for>
          </nav>
        </aside>
      </sc-if>

      <main class="wcf-page-main" style="flex:1;min-width:0;background:#f6f8f8;">

		        <sc-if value="{{ showPageHeader }}">
		        <div style="position:sticky;top:0;z-index:40;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.1),0 1px 1px rgba(0,0,0,.06);">
          <div style="display:flex;width:100%;gap:16px;align-items:center;justify-content:space-between;padding:20px 24px;flex-wrap:wrap;">
            <div style="display:flex;gap:8px;align-items:center;">
              <sc-if value="{{ showBack }}">
                <button class="wcf-button wcf-button--icon" type="button" sc-camel-on-click="{{ goBack }}" aria-label="Go back" style="width:36px;height:36px;border-radius:8px;cursor:pointer;"><i class="ph ph-arrow-left" style="font-size:20px;"></i></button>
              </sc-if>
		      <h1 style="font-size:22px;font-weight:600;color:#000;">{{ pageTitle }}</h1>
		        </div>
	            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
              <sc-for list="{{ headerActions }}" as="ha">
                <button sc-camel-on-click="{{ ha.onClick }}" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 24px;border-radius:6px;font-size:16px;font-weight:500;cursor:pointer;border:1px solid {{ ha.border }};background:{{ ha.bg }};color:{{ ha.color }};">
                  <i class="{{ ha.icon }}" style="font-size:16px;"></i>{{ ha.label }}
                </button>
              </sc-for>
	          </div>
	        </div>
        </div>
		        </sc-if>

        <sc-if value="{{ isDashboard }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <sc-if value="{{ bannerVisible }}">
              <div style="position:relative;display:flex;align-items:center;gap:16px;border-radius:14px;border:1px solid #fee685;background:#fefce8;padding:16px 56px 16px 20px;margin-bottom:16px;">
                <div style="display:flex;height:48px;width:48px;flex-shrink:0;align-items:center;justify-content:center;border-radius:50%;background:#ffba00;font-size:24px;box-shadow:0 1px 2px rgba(0,0,0,.06);">🍕</div>
                <div style="flex:1;min-width:0;">
                  <p style="margin:0;font-size:14px;font-weight:700;color:#101828;">Unlock Product Options for Your Menu!</p>
                  <p style="margin:2px 0 0;font-size:14px;color:#4a5565;">Let customers customize their orders - add toppings, sizes &amp; extras with <strong style="color:#1e2939;">Optiontics</strong>, a free plugin - grab it from here directly!</p>
                </div>
                <span style="flex-shrink:0;display:inline-flex;align-items:center;gap:8px;border-radius:8px;background:rgba(26,26,26,.9);padding:10px 16px;font-size:14px;font-weight:600;color:#fff;cursor:pointer;"><i class="ph ph-download-simple" style="font-size:15px;"></i>Download Free</span>
		        <button class="wcf-button wcf-button--icon" type="button" sc-camel-on-click="{{ dismissBanner }}" aria-label="Dismiss notice" style="position:absolute;right:12px;top:12px;height:24px;width:24px;border-radius:50%;cursor:pointer;"><i class="ph ph-x" aria-hidden="true" style="font-size:14px;"></i></button>
              </div>
            </sc-if>

            <div style="background:rgba(255,255,255,.9);border:1px solid #e5e5e5;border-radius:14px;padding:24px;margin-bottom:16px;">
              <div style="display:flex;align-items:center;flex-wrap:wrap;justify-content:space-between;gap:16px;margin-bottom:24px;">
                <div>
                  <h2 style="font-size:18px;font-weight:700;color:#000;margin:0;">Dashboard Overview</h2>
                  <p style="font-size:14px;color:rgba(0,0,0,.5);margin:2px 0 0;">Monitor your restaurant's key performance metrics and trends.</p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                  <label style="font-size:14px;color:rgba(0,0,0,.9);">Restaurant:</label>
                  <div style="display:flex;align-items:center;gap:8px;height:36px;min-width:180px;padding:0 12px;border:1px solid #d7d9e2;border-radius:8px;background:#fff;font-size:14px;"><i class="ph ph-storefront" style="font-size:15px;color:#6b7280;"></i>{{ siteName }}</div>
                  <div style="display:flex;align-items:center;gap:8px;height:36px;padding:0 12px;border:1px solid #d7d9e2;border-radius:8px;background:#fff;font-size:14px;"><i class="ph ph-clock" style="font-size:15px;color:#6b7280;"></i>Current store data</div>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(248px,1fr));gap:24px;">
                <sc-for list="{{ metrics }}" as="m">
                  <div style="position:relative;background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:10px;padding:24px;">
                    <div style="position:absolute;top:16px;right:16px;width:48px;height:48px;border-radius:50%;display:grid;place-items:center;background:{{ m.iconBg }};">
                      <i class="{{ m.icon }}" style="font-size:24px;color:#fff;"></i>
                    </div>
                    <div style="margin-bottom:8px;padding-right:64px;display:flex;align-items:center;gap:8px;">
                      <h3 style="font-size:14px;font-weight:500;color:rgba(0,0,0,.9);margin:0;">{{ m.title }}</h3>
                      <i class="ph ph-info" title="{{ m.tooltip }}" style="font-size:16px;color:rgba(0,0,0,.4);cursor:pointer;flex-shrink:0;"></i>
                    </div>
                    <div style="font-size:30px;line-height:1.2;font-weight:700;color:#000;margin-bottom:16px;">{{ m.value }}</div>
                    <sc-if value="{{ m.hasBreakdown }}">
                      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:6px;font-size:14px;color:rgba(0,0,0,.6);flex-shrink:0;"><span>Food order's - </span>{{ m.orders }}</div>
                        <div style="display:flex;align-items:center;gap:6px;font-size:14px;color:rgba(0,0,0,.6);flex-shrink:0;"><span>Reservation's - </span>{{ m.reservations }}</div>
                      </div>
                    </sc-if>
                    <sc-if value="{{ m.hasChange }}"><div style="display:flex;align-items:center;gap:8px;">
                      <div style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:500;background:{{ m.chipBg }};color:{{ m.chipColor }};">{{ m.change }}</div>
                      <span style="font-size:12px;color:rgba(0,0,0,.5);">since last period</span>
                    </div></sc-if>
                  </div>
                </sc-for>
              </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;width:100%;gap:20px;align-items:stretch;">
              <sc-for list="{{ widgets }}" as="w">
                <div style="flex:1 1 40%;min-width:340px;">
                  <div style="background:#fff;border-radius:10px;border:1px solid rgba(0,0,0,.15);width:100%;height:100%;">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid rgba(0,0,0,.1);">
                      <div style="display:flex;justify-content:flex-start;gap:6px;align-items:center;">
                        <h3 style="font-size:18px;font-weight:600;color:#000;margin:0;">{{ w.title }}</h3>
                        <i class="ph ph-info" title="{{ w.tooltip }}" style="font-size:16px;color:rgba(0,0,0,.7);cursor:pointer;"></i>
                      </div>
		              <button class="wcf-button" type="button" sc-camel-on-click="{{ w.onViewAll }}" style="padding:4px 10px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;">View All</button>
                    </div>
                    <div class="wcf-scroll" style="overflow-x:auto;max-height:400px;">
                      <sc-raw-table style="width:100%;caption-side:bottom;font-size:14px;">
                        <sc-raw-thead>
                          <sc-raw-tr style="border-bottom:1px solid rgba(0,0,0,.1);">
                            <sc-for list="{{ w.columns }}" as="wcol">
                              <sc-raw-th style="color:#71717a;font-weight:500;padding:0 16px;height:40px;text-align:left;white-space:nowrap;">{{ wcol.title }}</sc-raw-th>
                            </sc-for>
                          </sc-raw-tr>
                        </sc-raw-thead>
                        <sc-raw-tbody>
                          <sc-for list="{{ w.rows }}" as="row">
                            <sc-raw-tr style="border-bottom:1px solid rgba(0,0,0,.1);">
                              <sc-for list="{{ row.cells }}" as="cell">
                                <sc-raw-td style="padding:8px 16px;vertical-align:middle;color:rgba(0,0,0,.8);">
                                  <sc-if value="{{ cell.isBadge }}">
                                    <div style="display:inline-flex;align-items:center;gap:8px;border:1px solid #a5a9be;border-radius:8px;padding:4px 8px;">
                                      <span style="width:8px;height:8px;border-radius:50%;background:{{ cell.dot }};"></span>
                                      <span style="font-size:14px;color:rgba(0,0,0,.75);">{{ cell.text }}</span>
                                    </div>
                                  </sc-if>
                                  <sc-if value="{{ cell.isPlain }}">
                                    <span style="color:{{ cell.color }};font-weight:{{ cell.weight }};">{{ cell.text }}</span>
                                  </sc-if>
                                  <sc-if value="{{ cell.isStacked }}">
                                    <div>
                                      <div style="font-weight:500;">{{ cell.text }}</div>
                                      <div style="font-size:14px;color:#6a7282;">{{ cell.sub }}</div>
                                    </div>
                                  </sc-if>
                                </sc-raw-td>
                              </sc-for>
                            </sc-raw-tr>
                          </sc-for>
                        </sc-raw-tbody>
		              </sc-raw-table>
		              <sc-if value="{{ w.empty }}">
		                <div style="padding:32px 20px;text-align:center;color:#6b7280;font-size:14px;">{{ w.emptyDescription }}</div>
		              </sc-if>
                    </div>
                  </div>
                </div>
              </sc-for>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isFoodMenu }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="width:100%;max-width:1080px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:24px;box-shadow:0 1px 2px rgba(16,24,40,.03);">
              <div class="wcf-food-menu-grid">
                <div style="min-width:0;">
                  <sc-for list="{{ menuSections }}" as="sec">
                    <div style="width:100%;margin-bottom:18px;background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:20px 22px;box-shadow:0 1px 2px rgba(16,24,40,.03);">
                      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;">
                        <div style="flex:1;min-width:260px;">
                          <div style="display:flex;align-items:center;gap:10px;margin:0 0 8px;">
                            <span style="width:32px;height:32px;flex-shrink:0;border-radius:8px;background:#E9EDFF;color:#2B4BFF;display:grid;place-items:center;"><i class="{{ sec.icon }}" style="font-size:17px;"></i></span>
                            <h3 style="font-size:18px;font-weight:600;margin:0;color:#000;">{{ sec.title }}</h3>
                            <sc-if value="{{ sec.hasCount }}">
                              <span style="display:inline-flex;align-items:center;height:22px;padding:0 9px;border-radius:999px;background:#f1f1f4;color:#525266;font-size:11px;font-weight:600;white-space:nowrap;">{{ sec.count }}</span>
                            </sc-if>
                          </div>
                          <p style="max-width:560px;font-size:14px;line-height:1.5;margin:0;color:rgba(0,0,0,.7);">{{ sec.description }}</p>
                        </div>
                        <button sc-camel-on-click="{{ sec.onClick }}" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap;height:44px;padding:0 16px;border:1px solid #2B4BFF;background:#fff;color:#2B4BFF;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;" style-hover="background:#2B4BFF;color:#fff;">{{ sec.buttonText }}</button>
                      </div>
                    </div>
                  </sc-for>
                </div>
                <div style="min-width:0;">
                  <div style="background:#fff;border:1px solid #e5e5e5;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.03);">
                    <div style="padding:20px 22px;border-bottom:1px solid #e5e5e5;font-size:18px;font-weight:600;color:rgba(0,0,0,.9);">Food Menu Instructions</div>
                    <div style="padding:22px;display:flex;flex-direction:column;gap:24px;">
                      <sc-for list="{{ menuInstructions }}" as="ins">
                        <div style="display:grid;grid-template-columns:auto minmax(0,1fr);gap:12px;align-items:start;">
                          <span style="width:34px;height:34px;display:grid;place-items:center;border-radius:9px;background:#E9EDFF;color:#2B4BFF;"><i class="{{ ins.icon }}" style="font-size:17px;"></i></span>
                          <div style="min-width:0;">
                            <strong style="display:block;margin:0 0 6px;font-size:14px;color:#252525;">{{ ins.title }}</strong>
                            <p style="margin:0;font-size:13px;line-height:1.55;color:#62697a;">{{ ins.description }}</p>
                            <button class="wcf-button" type="button" sc-camel-on-click="{{ ins.onClick }}" style="margin-top:10px;min-height:34px;padding:0 12px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;">{{ ins.action }} <i class="ph ph-arrow-right" aria-hidden="true"></i></button>
                          </div>
                        </div>
                      </sc-for>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isReservations }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;padding:14px;background:#fff;border:1px solid #eff0f6;border-radius:10px 10px 0 0;">
              <div style="display:flex;align-items:center;gap:8px;">
                <button sc-camel-on-click="{{ toggleFilters }}" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 16px;border:1px solid rgba(0,0,0,.1);background:#fff;color:#6b7280;border-radius:6px;font-size:14px;cursor:pointer;"><i class="ph ph-funnel" style="font-size:16px;"></i>Filter</button>
                <div style="position:relative;width:320px;max-width:100%;">
                  <i class="ph ph-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:16px;color:#a5a9be;"></i>
                  <input value="{{ searchValue }}" sc-camel-on-input="{{ onSearch }}" placeholder="Search by customer name, email..." style="width:100%;height:44px;padding:0 12px 0 36px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;">
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
                <sc-if value="{{ hasSelection }}">
                  <button sc-camel-on-click="{{ deleteSelected }}" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 16px;border:1px solid #6b7280;background:transparent;color:#ef4444;border-radius:6px;font-size:14px;cursor:pointer;">Delete Selected ({{ selectedCount }})</button>
                </sc-if>
                <button sc-camel-on-click="{{ goCreateReservation }}" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 24px;background:#2B4BFF;color:#fff;border:none;border-radius:6px;font-size:16px;font-weight:500;cursor:pointer;" style-hover="background:#1E38D6;"><i class="ph ph-plus" style="font-size:16px;"></i>Create New Reservation</button>
              </div>
            </div>

            <sc-if value="{{ filtersOpen }}">
              <div style="padding:16px;background:#fff;border-left:1px solid #eff0f6;border-right:1px solid #eff0f6;border-bottom:1px solid #eff0f6;">
                <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <sc-for list="{{ statusFilters }}" as="sf">
		      <button type="button" sc-camel-on-click="{{ sf.onClick }}" style="display:inline-flex;align-items:center;gap:8px;height:36px;padding:0 14px;border-radius:8px;font-size:14px;cursor:pointer;border:1px solid {{ sf.border }};background:{{ sf.bg }};color:{{ sf.color }};">
                        <span style="width:8px;height:8px;border-radius:50%;background:{{ sf.dot }};"></span>{{ sf.label }}
		      </button>
                    </sc-for>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;height:36px;width:180px;padding:0 12px;border:1px solid #a5a9be;border-radius:8px;background:#fff;font-size:14px;color:#b4b1b1;cursor:pointer;">Filter by Location<i class="ph ph-caret-down" style="font-size:14px;color:#6b7280;"></i></div>
                    <div style="display:flex;align-items:center;gap:8px;height:36px;width:280px;padding:0 12px;border:1px solid #a5a9be;border-radius:8px;background:#fff;font-size:14px;color:#b4b1b1;cursor:pointer;"><i class="ph ph-calendar-blank" style="font-size:15px;color:#6b7280;"></i>Pick a date range</div>
                  </div>
                  <sc-if value="{{ filterApplied }}">
                    <button class="wcf-button wcf-button--danger" sc-camel-on-click="{{ clearFilters }}" style="display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 12px;border-radius:6px;font-size:14px;cursor:pointer;"><i class="ph ph-x-circle" style="font-size:16px;"></i>Clear Filters</button>
                  </sc-if>
                </div>
              </div>
            </sc-if>

            <div class="wcf-scroll" style="background:#fff;border:1px solid #e5e5e5;border-top:0;border-radius:0 0 10px 10px;overflow-x:auto;">
              <sc-raw-table style="width:100%;font-size:14px;">
                <sc-raw-thead>
                  <sc-raw-tr style="border-bottom:1px solid #e5e5e5;">
                    <sc-raw-th style="width:48px;padding:0 16px;height:40px;text-align:left;"><span style="display:inline-block;width:16px;height:16px;border:1px solid #a5a9be;border-radius:4px;"></span></sc-raw-th>
                    <sc-for list="{{ reservationColumns }}" as="rc">
                      <sc-raw-th style="color:rgba(0,0,0,.6);font-weight:500;padding:0 16px;height:40px;text-align:left;white-space:nowrap;">
                        <div style="display:flex;align-items:center;gap:8px;">{{ rc.title }}</div>
                      </sc-raw-th>
                    </sc-for>
                  </sc-raw-tr>
                </sc-raw-thead>
                <sc-raw-tbody>
                  <sc-for list="{{ reservationGroups }}" as="grp">
                    <sc-raw-tr style="background:rgba(255,255,255,.4);border-bottom:1px solid #e5e5e5;">
                      <sc-raw-td colspan="9" style="padding:12px 16px;">
                        <div style="display:flex;align-items:center;gap:12px;">
                          <span style="display:inline-block;width:16px;height:16px;border:1px solid #a5a9be;border-radius:4px;"></span>
                          <span style="font-size:14px;font-weight:600;color:#000;">{{ grp.date }}</span>
                          <span style="font-size:12px;color:#6b7280;">{{ grp.countLabel }}</span>
                        </div>
                      </sc-raw-td>
                    </sc-raw-tr>
                    <sc-for list="{{ grp.reservations }}" as="r">
                      <sc-raw-tr sc-camel-on-click="{{ r.onOpen }}" style="border-bottom:1px solid #e5e5e5;background:{{ r.rowBg }};cursor:pointer;">
                        <sc-raw-td style="padding:8px 16px;">
		            <button type="button" role="checkbox" aria-label="Select reservation {{ r.invoice }}" aria-checked="{{ r.selectedAria }}" sc-camel-on-click="{{ r.onToggle }}" style="display:inline-grid;place-items:center;width:20px;height:20px;padding:0;border:1px solid #a5a9be;border-radius:4px;cursor:pointer;background:{{ r.checkBg }};color:#fff;font-size:11px;">{{ r.check }}</button>
                        </sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;color:#6b7280;font-size:14px;">{{ r.time }}</sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;"><span style="font-size:14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ r.invoice }}</span></sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;">
                          <div style="display:flex;flex-direction:column;gap:6px;font-size:14px;">
                            <div style="color:#6b7280;display:flex;align-items:center;gap:4px;"><i class="ph ph-user" style="font-size:12px;"></i>{{ r.name }}</div>
                            <div style="color:#6b7280;display:flex;align-items:center;gap:4px;"><i class="ph ph-envelope-simple" style="font-size:12px;"></i>{{ r.email }}</div>
                          </div>
                        </sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;"><span style="font-weight:500;font-size:14px;color:{{ r.foodColor }};">{{ r.food }}</span></sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;"><span style="display:inline-flex;align-items:center;gap:4px;font-size:14px;"><i class="ph ph-users-three" style="font-size:12px;"></i>{{ r.guests }}</span></sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;"><span style="display:inline-flex;align-items:center;gap:4px;font-size:14px;"><i class="ph ph-credit-card" style="font-size:12px;"></i>{{ r.payment }}</span></sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;">
		            <button type="button" sc-camel-on-click="{{ r.cycleStatus }}" aria-label="Change status for {{ r.invoice }}" title="Change status" style="display:inline-flex;align-items:center;justify-content:space-between;gap:8px;width:144px;height:32px;padding:0 12px 0 8px;border:1px solid #e6e6f0;border-radius:8px;background:#fff;cursor:pointer;">
                            <span style="display:inline-flex;align-items:center;gap:8px;"><span style="width:8px;height:8px;border-radius:50%;background:{{ r.statusDot }};"></span><span style="font-size:14px;color:rgba(0,0,0,.75);">{{ r.status }}</span></span>
                            <i class="ph ph-caret-down" style="font-size:12px;color:#6b7280;"></i>
		            </button>
                        </sc-raw-td>
                        <sc-raw-td style="padding:8px 16px;">
                          <div style="display:flex;align-items:center;gap:6px;">
		              <button type="button" sc-camel-on-click="{{ r.onOpen }}" aria-label="Edit reservation {{ r.invoice }}" title="Edit reservation" style="width:32px;height:32px;display:grid;place-items:center;border-radius:6px;background:#f6f8f9;color:#1d222b;cursor:pointer;" style-hover="background:#eff0f6;"><i class="ph ph-pencil-simple" aria-hidden="true" style="font-size:16px;"></i></button>
		              <button type="button" sc-camel-on-click="{{ r.onDelete }}" aria-label="Delete reservation {{ r.invoice }}" title="Delete" style="width:32px;height:32px;display:grid;place-items:center;border-radius:6px;background:#f6f8f9;color:#1d222b;cursor:pointer;" style-hover="background:#fee2e2;color:#ef4444;"><i class="ph ph-trash" aria-hidden="true" style="font-size:16px;"></i></button>
                          </div>
                        </sc-raw-td>
                      </sc-raw-tr>
                    </sc-for>
                  </sc-for>
                </sc-raw-tbody>
              </sc-raw-table>
              <sc-if value="{{ noResults }}">
                <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;padding:56px 24px;">
                  <div style="display:grid;place-items:center;width:80px;height:80px;background:#f3f4f6;border-radius:50%;"><i class="ph ph-calendar-x" style="font-size:40px;color:#6a7282;"></i></div>
                  <div style="text-align:center;">
                    <h2 style="font-size:24px;font-weight:700;color:#000;margin:0 0 8px;">No reservations found!</h2>
                    <p style="color:#6a7282;margin:0;font-size:14px;">Try adjusting your search terms or filters to find what you're looking for.</p>
                  </div>
                  <button sc-camel-on-click="{{ clearFilters }}" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 20px;border:1px solid #2B4BFF;background:#fff;color:#2B4BFF;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;"><i class="ph ph-x-circle" style="font-size:16px;"></i>Clear Filters</button>
                </div>
              </sc-if>
              <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;border-top:1px solid #e5e5e5;flex-wrap:wrap;">
                <span style="font-size:14px;color:#6b7280;">{{ paginationLabel }}</span>
		              <sc-if value="{{ reservationHasPages }}"><div style="display:flex;align-items:center;gap:4px;">
                  <span style="min-width:32px;height:32px;display:grid;place-items:center;border:1px solid #e6e6f0;border-radius:6px;color:#6b7280;cursor:pointer;"><i class="ph ph-caret-left" style="font-size:14px;"></i></span>
                  <span style="min-width:32px;height:32px;display:grid;place-items:center;border:1px solid #2B4BFF;border-radius:6px;background:#2B4BFF;color:#fff;font-size:14px;">1</span>
                  <span style="min-width:32px;height:32px;display:grid;place-items:center;border:1px solid #e6e6f0;border-radius:6px;color:#414454;font-size:14px;cursor:pointer;">2</span>
                  <span style="min-width:32px;height:32px;display:grid;place-items:center;border:1px solid #e6e6f0;border-radius:6px;color:#6b7280;cursor:pointer;"><i class="ph ph-caret-right" style="font-size:14px;"></i></span>
		              </div></sc-if>
              </div>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isSettings }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="width:100%;max-width:{{ settingsMaxWidth }};margin:0 auto;">
            <sc-if value="{{ settingsHasIntro }}">
              <div style="background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:24px;margin-bottom:16px;box-shadow:0 1px 2px rgba(16,24,40,.03);">
                <h2 style="margin:0 0 10px;font-size:22px;line-height:1.25;color:#292d3e;letter-spacing:-.025em;">{{ settingsIntroTitle }}</h2>
                <p style="max-width:780px;margin:0;color:#62697a;font-size:15px;line-height:1.6;">
                  {{ settingsIntroDescription }}
                  <button class="wcf-button" type="button" sc-camel-on-click="{{ openReservationShortcode }}" style="min-height:34px;padding:0 12px;border-radius:7px;font:inherit;font-weight:600;cursor:pointer;">{{ settingsIntroLink }}</button>
                  {{ settingsIntroSuffix }}
                </p>
              </div>
            </sc-if>
            <sc-for list="{{ settingsSections }}" as="sec">
              <div style="background:#fff;border:1px solid #e5e5e5;border-radius:14px;margin-bottom:16px;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.03);">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 24px;min-height:76px;gap:12px;flex-wrap:wrap;border-bottom:1px solid {{ sec.dividerColor }};">
                  <div style="display:flex;flex-direction:column;gap:8px;">
		            <h2 style="color:rgba(0,0,0,.9);font-size:18px;font-weight:600;letter-spacing:-.025em;">{{ sec.title }}</h2>
                    <sc-if value="{{ sec.hasDescription }}">
                      <div style="color:#6b7280;font-weight:400;font-size:14px;">{{ sec.description }}</div>
                    </sc-if>
                  </div>
                  <sc-if value="{{ sec.switchable }}">
                    <button type="button" role="switch" aria-label="{{ sec.toggleLabel }}" aria-checked="{{ sec.ariaChecked }}" sc-camel-on-click="{{ sec.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;background:{{ sec.trackBg }};">
                      <span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s;left:{{ sec.knobLeft }};"></span>
                    </button>
                  </sc-if>
                </div>
                <sc-if value="{{ sec.open }}">
                  <div style="padding:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:22px 24px;align-items:start;">
                    <sc-for list="{{ sec.fields }}" as="f">
                      <div style="display:flex;flex-direction:column;gap:8px;grid-column:{{ f.span }};">
                        <sc-if value="{{ f.hasLabel }}">
		                <label for="{{ f.id }}" style="display:flex;align-items:center;gap:6px;font-size:14px;font-weight:600;color:#292d3e;">{{ f.label }}<sc-if value="{{ f.isRequired }}"><span aria-hidden="true" style="color:#dc2626;">*</span></sc-if><sc-if value="{{ f.hasTooltip }}"><i class="ph ph-info" title="{{ f.tooltip }}" style="font-size:16px;color:#62697a;"></i></sc-if></label>
                        </sc-if>
                        <sc-if value="{{ f.isInput }}">
		              <input id="{{ f.id }}" type="{{ f.inputType }}" value="{{ f.value }}" sc-camel-on-input="{{ f.onInput }}" placeholder="{{ f.placeholder }}" aria-invalid="{{ f.ariaInvalid }}" aria-required="{{ f.ariaRequired }}" aria-describedby="{{ f.describedBy }}" style="width:100%;height:44px;padding:0 12px;border:1px solid {{ f.border }};border-radius:6px;background:#fff;font-size:14px;outline:none;">
                        </sc-if>
                        <sc-if value="{{ f.hasError }}">
		              <div id="{{ f.errorId }}" role="alert" style="display:flex;align-items:center;gap:6px;color:#dc2626;font-size:12px;line-height:1.4;"><i class="ph ph-warning-circle" aria-hidden="true"></i>{{ f.error }}</div>
                        </sc-if>
                        <sc-if value="{{ f.isSelect }}">
		              <sc-raw-select id="{{ f.id }}" value="{{ f.value }}" sc-camel-on-change="{{ f.onInput }}" aria-describedby="{{ f.describedBy }}" style="width:100%;height:44px;padding:0 12px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;">
                            <sc-for list="{{ f.options }}" as="opt">
                              <option value="{{ opt.value }}">{{ opt.label }}</option>
                            </sc-for>
                          </sc-raw-select>
                        </sc-if>
                        <sc-if value="{{ f.isCompound }}">
                          <div style="display:flex;width:100%;height:44px;border:1px solid #a5a9be;border-radius:8px;background:#fff;overflow:hidden;">
                            <sc-if value="{{ f.hasPrefix }}"><span style="min-width:44px;display:grid;place-items:center;padding:0 12px;border-right:1px solid #a5a9be;color:#6b7280;font-size:15px;">{{ f.prefix }}</span></sc-if>
		                <input id="{{ f.id }}" type="{{ f.inputType }}" value="{{ f.value }}" sc-camel-on-input="{{ f.onInput }}" aria-describedby="{{ f.describedBy }}" style="min-width:0;flex:1;height:42px;padding:0 12px;border:0;background:#fff;font-size:14px;outline:none;box-shadow:none;">
                            <sc-if value="{{ f.hasSuffix }}"><span style="min-width:96px;display:grid;place-items:center;padding:0 14px;border-left:1px solid #a5a9be;color:#525866;font-size:14px;background:#fafafa;">{{ f.suffix }}</span></sc-if>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isTextarea }}">
		              <textarea id="{{ f.id }}" value="{{ f.value }}" sc-camel-on-input="{{ f.onInput }}" placeholder="{{ f.placeholder }}" aria-describedby="{{ f.describedBy }}" rows="4" style="width:100%;padding:12px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;resize:vertical;"></textarea>
                        </sc-if>
                        <sc-if value="{{ f.isToggle }}">
                          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border:1px solid #e5e5e5;border-radius:8px;">
                            <span style="font-size:14px;color:#414454;">{{ f.help }}</span>
                            <button type="button" role="switch" aria-label="{{ f.toggleLabel }}" aria-checked="{{ f.ariaChecked }}" sc-camel-on-click="{{ f.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;flex-shrink:0;background:{{ f.trackBg }};">
                              <span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s;left:{{ f.knobLeft }};"></span>
                            </button>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isSchedule }}">
                          <div style="border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">
                            <sc-for list="{{ f.days }}" as="day">
                              <div style="display:flex;align-items:center;gap:16px;padding:12px 16px;border-bottom:1px solid #f1f1f4;flex-wrap:wrap;">
		                  <button type="button" role="switch" aria-label="Open {{ day.label }}" aria-checked="{{ day.ariaChecked }}" sc-camel-on-click="{{ day.onToggle }}" style="position:relative;display:inline-block;width:40px;height:22px;padding:0;border-radius:999px;cursor:pointer;flex-shrink:0;background:{{ day.trackBg }};">
                                  <span style="position:absolute;top:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s;left:{{ day.knobLeft }};"></span>
		                  </button>
                                <span style="width:96px;font-size:14px;font-weight:500;color:#252525;">{{ day.label }}</span>
                                <span style="font-size:14px;color:{{ day.timeColor }};">{{ day.hours }}</span>
                              </div>
                            </sc-for>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isFieldList }}">
                          <div style="border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">
                            <sc-for list="{{ f.items }}" as="fi">
                              <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f1f1f4;">
                                <i class="ph ph-dots-six-vertical" style="font-size:18px;color:#a5a9be;cursor:grab;"></i>
                                <span style="flex:1;font-size:14px;color:#252525;">{{ fi.label }}</span>
                                <span style="display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:999px;background:#f6f6f6;color:#525266;font-size:12px;">{{ fi.type }}</span>
                                <sc-if value="{{ fi.required }}">
                                  <span style="display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:999px;background:#E9EDFF;color:#2B4BFF;font-size:12px;">Required</span>
                                </sc-if>
                                <i class="ph ph-pencil-simple" style="font-size:16px;color:#6b7280;cursor:pointer;"></i>
                              </div>
                            </sc-for>
		              <button class="wcf-button" type="button" sc-camel-on-click="{{ f.onAdd }}" style="width:100%;display:flex;align-items:center;gap:8px;padding:14px 16px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;"><i class="ph ph-plus-circle" aria-hidden="true" style="font-size:18px;"></i>Add New Custom Field</button>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isReservationFields }}">
                          <div style="border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;background:#fff;">
                            <div style="display:grid;grid-template-columns:minmax(0,1fr) 92px 48px;gap:12px;align-items:center;padding:12px 16px;background:#fafafa;border-bottom:1px solid #e5e5e5;color:#6b7280;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">
                              <span>Field name</span><span>Visible</span><span>Action</span>
                            </div>
                            <sc-for list="{{ f.reservationRows }}" as="rf">
                              <div style="border-bottom:1px solid #eef0f4;">
                                <div style="display:grid;grid-template-columns:minmax(0,1fr) 92px 48px;gap:12px;align-items:center;padding:14px 16px;">
                                  <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                                    <i class="{{ rf.lockIcon }}" style="font-size:18px;color:#62697a;flex-shrink:0;"></i>
                                    <span style="min-width:0;display:flex;flex-direction:column;gap:3px;"><strong style="font-size:14px;color:#252525;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ rf.label }}</strong><span style="font-size:12px;color:#7b8190;">{{ rf.type }}</span></span>
                                  </div>
                                  <button type="button" role="switch" aria-label="Show {{ rf.label }}" aria-checked="{{ rf.ariaChecked }}" sc-camel-on-click="{{ rf.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;background:{{ rf.trackBg }};"><span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);left:{{ rf.knobLeft }};"></span></button>
                                  <button type="button" aria-label="Edit {{ rf.label }}" sc-camel-on-click="{{ rf.onEdit }}" style="width:36px;height:36px;display:grid;place-items:center;border:1px solid #e5e5e5;border-radius:7px;background:#fff;color:#525866;cursor:pointer;"><i class="ph ph-pencil-simple" aria-hidden="true"></i></button>
                                </div>
                                <sc-if value="{{ rf.editing }}">
                                  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;padding:0 16px 16px 46px;background:#fafbff;">
                                    <label style="display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:#525866;">Field label<input value="{{ rf.labelValue }}" sc-camel-on-input="{{ rf.onLabel }}" style="height:40px;padding:0 10px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;"></label>
                                    <label style="display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:#525866;">Placeholder<input value="{{ rf.placeholderValue }}" sc-camel-on-input="{{ rf.onPlaceholder }}" style="height:40px;padding:0 10px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;"></label>
                                    <div style="display:flex;align-items:flex-end;"><div style="width:100%;min-height:40px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 10px;border:1px solid #e5e5e5;border-radius:6px;background:#fff;"><span style="font-size:13px;color:#414454;">Required field</span><button type="button" role="switch" aria-label="Require {{ rf.label }}" aria-checked="{{ rf.requiredAriaChecked }}" sc-camel-on-click="{{ rf.onRequired }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;background:{{ rf.requiredTrackBg }};"><span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);left:{{ rf.requiredKnobLeft }};"></span></button></div></div>
                                  </div>
                                </sc-if>
                              </div>
                            </sc-for>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isRepeater }}">
                          <div style="display:flex;flex-direction:column;gap:12px;">
                            <sc-for list="{{ f.repeaterRows }}" as="rr">
                              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;align-items:end;padding:14px;border:1px solid #e5e5e5;border-radius:8px;background:#fafafa;">
                                <sc-for list="{{ rr.cells }}" as="rc">
                                  <label style="display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:#525866;">{{ rc.label }}
                                    <sc-if value="{{ rc.isSelect }}"><sc-raw-select value="{{ rc.value }}" sc-camel-on-change="{{ rc.onInput }}" style="width:100%;height:40px;padding:0 10px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;"><sc-for list="{{ rc.options }}" as="ro"><option value="{{ ro.value }}">{{ ro.label }}</option></sc-for></sc-raw-select></sc-if>
                                    <sc-if value="{{ rc.isInput }}"><input type="{{ rc.inputType }}" value="{{ rc.value }}" sc-camel-on-input="{{ rc.onInput }}" placeholder="{{ rc.placeholder }}" style="width:100%;height:40px;padding:0 10px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;"></sc-if>
                                  </label>
                                </sc-for>
                                <sc-if value="{{ rr.canRemove }}"><button type="button" sc-camel-on-click="{{ rr.onRemove }}" aria-label="Remove custom field" style="width:40px;height:40px;display:grid;place-items:center;border:1px solid #d1d5db;border-radius:6px;background:#fff;color:#6b7280;cursor:pointer;"><i class="ph ph-trash" aria-hidden="true"></i></button></sc-if>
                              </div>
                            </sc-for>
                            <button type="button" sc-camel-on-click="{{ f.onAdd }}" style="align-self:flex-start;min-height:40px;padding:0 14px;border:1px solid #2B4BFF;background:#fff;color:#2B4BFF;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;"><i class="ph ph-plus" aria-hidden="true"></i> {{ f.addLabel }}</button>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isMessageCard }}">
                          <div style="border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;background:#fff;">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid #eef0f4;">
                              <div><strong style="display:block;margin-bottom:6px;font-size:15px;color:#252525;">{{ f.cardTitle }}</strong><p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">{{ f.cardDescription }}</p></div>
                              <button type="button" role="switch" aria-label="{{ f.toggleLabel }}" aria-checked="{{ f.ariaChecked }}" sc-camel-on-click="{{ f.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;flex-shrink:0;background:{{ f.trackBg }};"><span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);left:{{ f.knobLeft }};"></span></button>
                            </div>
                            <label style="display:flex;flex-direction:column;gap:8px;padding:16px 20px;font-size:13px;font-weight:600;color:#414454;">Message<textarea value="{{ f.messageValue }}" sc-camel-on-input="{{ f.onInput }}" placeholder="{{ f.messagePlaceholder }}" rows="3" style="width:100%;padding:12px;border:1px solid #a5a9be;border-radius:7px;background:#fff;font-size:14px;line-height:1.5;resize:vertical;"></textarea></label>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isButtonLabels }}">
                          <div style="border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;background:#fff;">
                            <div style="padding:18px 20px;border-bottom:1px solid #eef0f4;"><strong style="display:block;margin-bottom:6px;font-size:15px;color:#252525;">{{ f.cardTitle }}</strong><p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">{{ f.cardDescription }}</p></div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;padding:16px 20px;"><sc-for list="{{ f.buttonFields }}" as="bf"><label style="display:flex;flex-direction:column;gap:7px;font-size:12px;font-weight:600;color:#414454;">{{ bf.label }}<input value="{{ bf.value }}" sc-camel-on-input="{{ bf.onInput }}" style="height:42px;padding:0 11px;border:1px solid #a5a9be;border-radius:7px;background:#fff;font-size:14px;"></label></sc-for></div>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isPaymentToggle }}">
                          <div style="display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px;border:1px solid #e5e5e5;border-radius:10px;background:#fff;">
                            <div><strong style="display:block;margin-bottom:7px;font-size:15px;color:#252525;">{{ f.cardTitle }}</strong><p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">{{ f.cardDescription }}</p></div>
                            <button type="button" role="switch" aria-label="{{ f.toggleLabel }}" aria-checked="{{ f.ariaChecked }}" sc-camel-on-click="{{ f.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;flex-shrink:0;background:{{ f.trackBg }};"><span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);left:{{ f.knobLeft }};"></span></button>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isMultiSelect }}">
                          <div style="min-height:44px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;padding:7px 34px 7px 8px;border:1px solid #a5a9be;border-radius:8px;background:#fff;position:relative;">
                            <sc-for list="{{ f.multiOptions }}" as="option">
                              <sc-if value="{{ option.visible }}"><button type="button" sc-camel-on-click="{{ option.onToggle }}" style="display:inline-flex;align-items:center;gap:5px;min-height:28px;padding:0 9px;border:1px solid {{ option.border }};border-radius:999px;background:{{ option.bg }};color:{{ option.color }};font-size:12px;cursor:pointer;">{{ option.label }}<sc-if value="{{ option.selected }}"><i class="ph ph-x" style="font-size:12px;"></i></sc-if></button></sc-if>
                            </sc-for>
                            <button class="wcf-button wcf-button--icon" type="button" aria-label="Show reservation statuses" sc-camel-on-click="{{ f.onClick }}" style="position:absolute;right:4px;top:4px;width:34px;height:34px;border-radius:7px;cursor:pointer;"><i class="{{ f.menuCaret }}" style="font-size:14px;"></i></button>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isFeatureToggle }}">
                          <div style="display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:14px;align-items:start;padding:18px;border:1px solid #e5e5e5;border-radius:12px;background:#fff;">
                            <span style="width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:#E9EDFF;color:#2B4BFF;"><i class="{{ f.featureIcon }}" style="font-size:21px;"></i></span>
                            <div style="min-width:0;">
                              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:1px 0 6px;"><strong style="font-size:15px;color:#252525;">{{ f.featureTitle }}</strong><span style="display:inline-flex;align-items:center;min-height:24px;padding:0 9px;border-radius:999px;background:#f1f2f6;color:#62697a;font-size:11px;font-weight:600;">{{ f.featureBadge }}</span></div>
                              <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">{{ f.featureDescription }}</p>
                              <sc-if value="{{ f.hasAction }}"><button type="button" sc-camel-on-click="{{ f.onClick }}" style="margin-top:12px;min-height:38px;padding:0 14px;border:1px solid #2B4BFF;border-radius:7px;background:#fff;color:#2B4BFF;font-size:13px;font-weight:600;cursor:pointer;">{{ f.actionLabel }}</button></sc-if>
                            </div>
                            <button type="button" role="switch" aria-label="{{ f.toggleLabel }}" aria-checked="{{ f.ariaChecked }}" sc-camel-on-click="{{ f.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;background:{{ f.trackBg }};"><span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s;left:{{ f.knobLeft }};"></span></button>
                          </div>
                        </sc-if>
                        <sc-if value="{{ f.isNote }}">
                          <div style="background:#f6f6f6;border-left:4px solid #2B4BFF;border-radius:8px;padding:16px;font-size:14px;color:#414454;">{{ f.value }}</div>
                        </sc-if>
						<sc-if value="{{ f.isLink }}">
						  <button type="button" sc-camel-on-click="{{ f.onClick }}" style="justify-self:start;display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:0 16px;border:1px solid #2B4BFF;border-radius:7px;background:#fff;color:#2B4BFF;font-size:14px;font-weight:600;cursor:pointer;"><i class="ph ph-arrow-square-out" aria-hidden="true"></i>{{ f.label }}</button>
						</sc-if>
		                <sc-if value="{{ f.hasHelp }}">
		                  <span id="{{ f.helpId }}" style="font-size:12px;color:#6b7280;">{{ f.help }}</span>
                        </sc-if>
                      </div>
                    </sc-for>
                  </div>
                </sc-if>
              </div>
            </sc-for>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isSetupWizard }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="max-width:960px;margin:0 auto;">
              <div style="background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:24px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                  <div>
                    <h2 style="font-size:20px;font-weight:700;color:#000;margin:0 0 6px;">Finish your restaurant setup</h2>
                    <p style="font-size:14px;color:#6b7280;margin:0;">Complete the launch essentials without leaving the WowRestro workspace.</p>
                  </div>
                  <span style="display:inline-flex;align-items:center;height:30px;padding:0 12px;border-radius:999px;background:#E9EDFF;color:#2B4BFF;font-size:12px;font-weight:700;">{{ setupComplete }}</span>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
                <sc-for list="{{ setupSteps }}" as="step">
                  <button type="button" sc-camel-on-click="{{ step.onClick }}" style="font:inherit;text-align:left;width:100%;background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px;display:flex;align-items:flex-start;gap:16px;cursor:pointer;" style-hover="border-color:#2B4BFF;box-shadow:0 4px 12px rgba(43,75,255,.08);">
                    <span style="width:42px;height:42px;flex-shrink:0;border-radius:10px;background:#E9EDFF;color:#2B4BFF;display:grid;place-items:center;"><i class="{{ step.icon }}" style="font-size:20px;"></i></span>
                    <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:6px;">
                      <span style="font-size:16px;font-weight:600;color:#101828;">{{ step.title }}</span>
                      <span style="font-size:14px;line-height:1.5;color:#6b7280;">{{ step.description }}</span>
                      <span style="display:inline-flex;align-items:center;gap:6px;align-self:flex-start;height:26px;padding:0 9px;border-radius:999px;background:{{ step.bg }};color:{{ step.color }};font-size:11px;font-weight:700;"><i class="{{ step.statusIcon }}" style="font-size:13px;"></i>{{ step.status }}</span>
                    </span>
                    <i class="ph ph-caret-right" style="font-size:17px;color:#a5a9be;margin-top:12px;"></i>
                  </button>
                </sc-for>
              </div>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isDiagnostics }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="max-width:960px;margin:0 auto;">
              <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px;">
                <sc-for list="{{ diagnosticChecks }}" as="check">
                  <div style="background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;display:flex;align-items:flex-start;gap:14px;">
                    <span style="width:38px;height:38px;flex-shrink:0;border-radius:50%;display:grid;place-items:center;background:{{ check.bg }};color:{{ check.color }};"><i class="{{ check.icon }}" style="font-size:20px;"></i></span>
                    <div style="flex:1;min-width:0;">
                      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <div style="font-size:15px;font-weight:600;color:#101828;">{{ check.label }}</div>
                        <span style="font-size:12px;font-weight:700;color:{{ check.color }};">{{ check.status }}</span>
                      </div>
                      <p style="font-size:14px;line-height:1.5;color:#6b7280;margin:5px 0 0;">{{ check.description }}</p>
                    </div>
                  </div>
                </sc-for>
              </div>
              <div style="background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;">
                <div style="padding:16px 20px;border-bottom:1px solid #e5e5e5;font-size:16px;font-weight:600;color:#101828;">Environment</div>
                <sc-for list="{{ diagnosticEnvironment }}" as="env">
                  <div style="display:grid;grid-template-columns:minmax(180px,1fr) 2fr;gap:16px;padding:13px 20px;border-bottom:1px solid #f1f1f4;font-size:14px;">
                    <span style="font-weight:500;color:#414454;">{{ env.label }}</span>
                    <span style="color:#6b7280;overflow-wrap:anywhere;">{{ env.value }}</span>
                  </div>
                </sc-for>
              </div>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isLiveOrders }}">
	          <div class="wra-root wowrestro-live-board" style="width:100%;margin:0 auto;padding:16px;">
	            <div class="wra-head">
	              <div>
	                <h1 class="wra-title"><?php esc_html_e( 'Live Orders', 'wowrestro' ); ?></h1>
	                <p class="wra-boardstatus" data-wowrestro-status aria-live="polite"></p>
	              </div>
	              <div class="wra-head__actions">
	                <?php if ( current_user_can( 'wowrestro_manage_operations' ) || current_user_can( 'manage_woocommerce' ) ) : ?>
	                <button class="wra-btn" type="button" data-wowrestro-pause aria-pressed="false"><i class="ph-fill ph-pause-circle" aria-hidden="true"></i><?php esc_html_e( 'Pause ordering', 'wowrestro' ); ?></button>
	                <?php endif; ?>
	                <button class="wra-btn" type="button" data-wowrestro-refresh><i class="ph ph-arrow-clockwise" aria-hidden="true"></i><?php esc_html_e( 'Refresh orders', 'wowrestro' ); ?></button>
	                <button class="wra-btn" type="button" data-wowrestro-alerts aria-pressed="false"><i class="ph ph-speaker-simple-slash" aria-hidden="true"></i><?php esc_html_e( 'Enable sound alerts', 'wowrestro' ); ?></button>
	                <button class="wra-btn" type="button" data-wowrestro-print onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i><?php esc_html_e( 'Print board', 'wowrestro' ); ?></button>
	              </div>
	            </div>

            <div class="wra-bar">
              <div class="wra-bar__pills" data-wowrestro-filters></div>
              <div class="wra-bar__end">
	                <span class="wra-search"><i class="ph ph-magnifying-glass" aria-hidden="true"></i><input type="search" data-wowrestro-search placeholder="<?php esc_attr_e( 'Search order # or customer', 'wowrestro' ); ?>" aria-label="<?php esc_attr_e( 'Search orders', 'wowrestro' ); ?>"></span>
              </div>
            </div>

	            <?php if ( current_user_can( 'wowrestro_create_phone_orders' ) || current_user_can( 'manage_woocommerce' ) ) : ?>
	            <details class="wra-manual">
	              <summary><i class="ph ph-plus" aria-hidden="true"></i><?php esc_html_e( 'Add phone order', 'wowrestro' ); ?></summary>
              <div class="wra-manual__body">
                <form data-wowrestro-manual-form>
                  <div class="wra-manual__grid">
                    <label><?php esc_html_e( 'Customer name', 'wowrestro' ); ?><input type="text" name="customer" required></label>
                    <label><?php esc_html_e( 'Phone', 'wowrestro' ); ?><input name="phone" type="tel"></label>
                    <label><?php esc_html_e( 'Email', 'wowrestro' ); ?><input name="email" type="email"></label>
                    <label><?php esc_html_e( 'Service', 'wowrestro' ); ?><select name="mode"><option value="pickup"><?php esc_html_e( 'Pickup', 'wowrestro' ); ?></option><option value="delivery"><?php esc_html_e( 'Delivery', 'wowrestro' ); ?></option><option value="dinein"><?php esc_html_e( 'Dine-in', 'wowrestro' ); ?></option></select></label>
                    <label data-wowrestro-service-field="dinein"><?php esc_html_e( 'Table', 'wowrestro' ); ?><input type="text" name="table"></label>
                    <label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Address', 'wowrestro' ); ?><input type="text" name="address_1" autocomplete="street-address"></label>
                    <label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Address line 2', 'wowrestro' ); ?><input type="text" name="address_2"></label>
                    <label data-wowrestro-service-field="delivery"><?php esc_html_e( 'City', 'wowrestro' ); ?><input type="text" name="city" autocomplete="address-level2"></label>
                    <label data-wowrestro-service-field="delivery"><?php esc_html_e( 'State / region', 'wowrestro' ); ?><input type="text" name="state" autocomplete="address-level1"></label>
                    <label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Postcode', 'wowrestro' ); ?><input type="text" name="postcode"></label>
                    <label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Country code', 'wowrestro' ); ?><input type="text" name="country" maxlength="2" placeholder="US"></label>
                    <label><?php esc_html_e( 'Requested time', 'wowrestro' ); ?><input name="requested_at" type="datetime-local"></label>
                    <label><?php esc_html_e( 'Payment', 'wowrestro' ); ?><select name="payment_flow"><option value="pay_later"><?php esc_html_e( 'Pay later', 'wowrestro' ); ?></option><option value="payment_link"><?php esc_html_e( 'Send payment link', 'wowrestro' ); ?></option></select></label>
                  </div>
                  <div class="wra-manual__items" data-wowrestro-manual-items></div>
                  <div class="wra-manual__actions"><button type="button" class="wra-btn wra-btn--sm" data-wowrestro-add-item><?php esc_html_e( 'Add another item', 'wowrestro' ); ?></button></div>
                  <div class="wra-manual__grid">
                    <label><?php esc_html_e( 'WooCommerce service method', 'wowrestro' ); ?><select name="shipping_rate_id" data-wowrestro-shipping-rate disabled><option value=""><?php esc_html_e( 'Check availability first', 'wowrestro' ); ?></option></select></label>
                  </div>
                  <label class="wra-manual__note"><?php esc_html_e( 'Kitchen note', 'wowrestro' ); ?><textarea name="note" rows="2"></textarea></label>
                  <label class="wra-manual__consent"><input type="checkbox" name="status_opt_in" value="1"> <?php esc_html_e( 'Customer consented to status updates', 'wowrestro' ); ?></label>
                  <p class="wra-manual__message" data-wowrestro-manual-message aria-live="polite"></p>
                  <div class="wra-manual__actions"><button class="wra-btn" type="button" data-wowrestro-quote><?php esc_html_e( 'Check availability', 'wowrestro' ); ?></button><button class="wra-btn wra-btn--primary" type="submit"><?php esc_html_e( 'Create WooCommerce order', 'wowrestro' ); ?></button></div>
                </form>
              </div>
            </details>
            <?php endif; ?>

            <div class="wra-tickets" data-wowrestro-board><p><?php esc_html_e( 'Loading active orders…', 'wowrestro' ); ?></p></div>
          </div>
        </sc-if>

        <sc-if value="{{ isList }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;padding:14px;background:#fff;border:1px solid #eff0f6;border-radius:10px 10px 0 0;">
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="position:relative;width:320px;max-width:100%;">
                  <i class="ph ph-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:16px;color:#a5a9be;"></i>
		        <input value="{{ listSearch }}" sc-camel-on-input="{{ onListSearch }}" placeholder="{{ listSearchPlaceholder }}" aria-label="{{ listSearchPlaceholder }}" style="width:100%;height:44px;padding:0 12px 0 36px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;">
                </div>
              </div>
              <sc-if value="{{ listHasAdd }}">
                <button sc-camel-on-click="{{ listOnAdd }}" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 24px;background:#2B4BFF;color:#fff;border:none;border-radius:6px;font-size:16px;font-weight:500;cursor:pointer;" style-hover="background:#1E38D6;"><i class="ph ph-plus" style="font-size:16px;"></i>{{ listAddLabel }}</button>
              </sc-if>
		    </div>
		    <sc-if value="{{ listHasNotice }}"><div role="note" style="padding:12px 14px;border:1px solid #c7d2fe;border-top:0;background:#eef2ff;color:#3730a3;font-size:13px;">{{ listNotice }}</div></sc-if>
		    <div class="wcf-scroll" style="background:#fff;border:1px solid #e5e5e5;border-top:0;border-radius:0 0 10px 10px;overflow-x:auto;">
              <sc-raw-table style="width:100%;font-size:14px;">
                <sc-raw-thead>
                  <sc-raw-tr style="border-bottom:1px solid #e5e5e5;">
                    <sc-for list="{{ listColumns }}" as="lc">
                      <sc-raw-th style="color:rgba(0,0,0,.6);font-weight:500;padding:0 16px;height:40px;text-align:left;white-space:nowrap;">{{ lc.title }}</sc-raw-th>
                    </sc-for>
                  </sc-raw-tr>
                </sc-raw-thead>
                <sc-raw-tbody>
                  <sc-for list="{{ listRows }}" as="lr">
                    <sc-raw-tr style="border-bottom:1px solid #e5e5e5;">
                      <sc-for list="{{ lr.cells }}" as="lcell">
                        <sc-raw-td style="padding:10px 16px;vertical-align:middle;color:rgba(0,0,0,.8);">
                          <sc-if value="{{ lcell.isBadge }}">
                            <div style="display:inline-flex;align-items:center;gap:8px;border:1px solid #a5a9be;border-radius:8px;padding:4px 8px;">
                              <span style="width:8px;height:8px;border-radius:50%;background:{{ lcell.dot }};"></span>
                              <span style="font-size:14px;color:rgba(0,0,0,.75);">{{ lcell.text }}</span>
                            </div>
                          </sc-if>
                          <sc-if value="{{ lcell.isPlain }}">
                            <span style="color:{{ lcell.color }};font-weight:{{ lcell.weight }};">{{ lcell.text }}</span>
                          </sc-if>
                          <sc-if value="{{ lcell.isStacked }}">
                            <div>
                              <div style="font-weight:500;">{{ lcell.text }}</div>
                              <div style="max-width:360px;overflow:hidden;text-overflow:ellipsis;font-size:14px;color:#6a7282;">{{ lcell.sub }}</div>
                            </div>
                          </sc-if>
	                      <sc-if value="{{ lcell.isQrThumb }}">
	                        <div class="wcf-qr-thumb">
	                          <span class="wcf-qr-thumb__image"><img src="{{ lcell.mediaUrl }}" alt="" width="56" height="56"></span>
	                          <span><strong>{{ lcell.text }}</strong><small>{{ lcell.sub }}</small></span>
	                        </div>
	                      </sc-if>
                          <sc-if value="{{ lcell.isActions }}">
                            <div style="display:flex;align-items:center;gap:6px;">
		              <button type="button" sc-camel-on-click="{{ lcell.onEdit }}" title="Edit" aria-label="Edit" style="width:32px;height:32px;display:grid;place-items:center;border-radius:6px;background:#f6f8f9;color:#1d222b;cursor:pointer;" style-hover="background:#eff0f6;"><i class="ph ph-pencil-simple" aria-hidden="true" style="font-size:16px;"></i></button>
		              <sc-if value="{{ lcell.hasDelete }}"><button type="button" sc-camel-on-click="{{ lcell.onDelete }}" title="Delete" aria-label="Delete" style="width:32px;height:32px;display:grid;place-items:center;border-radius:6px;background:#f6f8f9;color:#1d222b;cursor:pointer;" style-hover="background:#fee2e2;color:#ef4444;"><i class="ph ph-trash" aria-hidden="true" style="font-size:16px;"></i></button></sc-if>
                            </div>
                          </sc-if>
	                      <sc-if value="{{ lcell.isQrActions }}">
	                        <div class="wcf-qr-actions">
	                          <button type="button" sc-camel-on-click="{{ lcell.onPreview }}" title="Preview table card" aria-label="Preview QR table card"><i class="ph ph-eye" aria-hidden="true"></i></button>
	                          <button type="button" sc-camel-on-click="{{ lcell.onOpen }}" title="Open destination" aria-label="Open QR destination"><i class="ph ph-arrow-square-out" aria-hidden="true"></i></button>
	                          <button type="button" sc-camel-on-click="{{ lcell.onCopy }}" title="Copy menu link" aria-label="Copy QR destination"><i class="ph ph-copy" aria-hidden="true"></i></button>
	                          <button type="button" sc-camel-on-click="{{ lcell.onDownload }}" title="Download table card" aria-label="Download print-ready QR table card"><i class="ph ph-download-simple" aria-hidden="true"></i></button>
	                          <button class="is-danger" type="button" sc-camel-on-click="{{ lcell.onDelete }}" title="Delete" aria-label="Delete QR code"><i class="ph ph-trash" aria-hidden="true"></i></button>
	                        </div>
	                      </sc-if>
                          <sc-if value="{{ lcell.isAvatar }}">
                            <div style="display:flex;align-items:center;gap:12px;min-width:220px;">
                              <span style="width:40px;height:40px;flex-shrink:0;border-radius:8px;display:grid;place-items:center;font-size:16px;color:#fff;background:{{ lcell.dot }};overflow:hidden;">
                                <sc-if value="{{ lcell.hasImage }}"><img src="{{ lcell.image }}" alt="" style="width:100%;height:100%;object-fit:cover;"></sc-if>
                                <sc-if value="{{ lcell.noImage }}"><i class="{{ lcell.icon }}"></i></sc-if>
                              </span>
                              <div>
                                <div style="font-weight:500;color:#101828;">{{ lcell.text }}</div>
                                <div style="font-size:13px;color:#6a7282;">{{ lcell.sub }}</div>
                              </div>
                            </div>
                          </sc-if>
                          <sc-if value="{{ lcell.isChip }}">
                            <span style="display:inline-flex;align-items:center;gap:6px;height:26px;padding:0 10px;border-radius:999px;font-size:12px;font-weight:600;background:{{ lcell.bg }};color:{{ lcell.dot }};">
				              <sc-if value="{{ lcell.hasIcon }}"><i class="{{ lcell.icon }}" style="font-size:13px;"></i></sc-if>{{ lcell.text }}
                            </span>
                          </sc-if>
                          <sc-if value="{{ lcell.isChips }}">
                            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                              <sc-for list="{{ lcell.chips }}" as="chip">
                                <span style="display:inline-flex;align-items:center;gap:5px;height:24px;padding:0 9px;border-radius:999px;font-size:11px;font-weight:600;background:{{ chip.bg }};color:{{ chip.color }};">
                                  <i class="{{ chip.icon }}" style="font-size:12px;"></i>{{ chip.label }}
                                </span>
                              </sc-for>
                              <sc-if value="{{ lcell.chipsEmpty }}">
                                <span style="font-size:13px;color:#a5a9be;">-</span>
                              </sc-if>
                            </div>
                          </sc-if>
                          <sc-if value="{{ lcell.isPrice }}">
                            <div style="display:flex;align-items:center;gap:8px;">
                              <span style="font-weight:500;color:#101828;">{{ lcell.text }}</span>
                              <sc-if value="{{ lcell.hasSale }}">
                                <span style="font-size:13px;color:#a5a9be;text-decoration:line-through;">{{ lcell.sub }}</span>
                              </sc-if>
                            </div>
                          </sc-if>
                        </sc-raw-td>
                      </sc-for>
                    </sc-raw-tr>
                  </sc-for>
                </sc-raw-tbody>
              </sc-raw-table>
              <sc-if value="{{ listEmpty }}">
                <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;padding:56px 24px;">
                  <div style="display:grid;place-items:center;width:80px;height:80px;background:#f3f4f6;border-radius:50%;"><i class="ph ph-magnifying-glass" style="font-size:40px;color:#6a7282;"></i></div>
                  <div style="text-align:center;">
                    <h2 style="font-size:24px;font-weight:700;color:#000;margin:0 0 8px;">{{ listEmptyTitle }}</h2>
                    <p style="color:#6a7282;margin:0;font-size:14px;">{{ listEmptyDescription }}</p>
                  </div>
                </div>
              </sc-if>
              <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;border-top:1px solid #e5e5e5;flex-wrap:wrap;">
                <span style="font-size:14px;color:#6b7280;">{{ listCountLabel }}</span>
                <div style="display:flex;align-items:center;gap:4px;">
                  <span style="min-width:32px;height:32px;display:grid;place-items:center;border:1px solid #e6e6f0;border-radius:6px;color:#6b7280;cursor:pointer;"><i class="ph ph-caret-left" style="font-size:14px;"></i></span>
                  <span style="min-width:32px;height:32px;display:grid;place-items:center;border:1px solid #2B4BFF;border-radius:6px;background:#2B4BFF;color:#fff;font-size:14px;">1</span>
                  <span style="min-width:32px;height:32px;display:grid;place-items:center;border:1px solid #e6e6f0;border-radius:6px;color:#6b7280;cursor:pointer;"><i class="ph ph-caret-right" style="font-size:14px;"></i></span>
                </div>
              </div>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isForm }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="max-width:{{ formMaxWidth }};margin:0 auto;">
              <sc-for list="{{ formSections }}" as="fs">
                <div style="background:#fff;border:1px solid #e5e5e5;border-radius:10px;margin-bottom:16px;">
                  <div style="padding:16px;border-bottom:1px solid #e5e5e5;">
		        <h2 style="color:rgba(0,0,0,.9);font-size:16px;font-weight:500;letter-spacing:-.025em;">{{ fs.title }}</h2>
                    <div style="color:#6b7280;font-size:14px;margin-top:4px;">{{ fs.description }}</div>
                  </div>
                  <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;align-items:start;">
                    <sc-for list="{{ fs.fields }}" as="ff">
                      <div style="display:flex;flex-direction:column;gap:8px;grid-column:{{ ff.span }};">
                        <sc-if value="{{ ff.hasLabel }}">
		          <label for="{{ ff.id }}" style="font-size:14px;font-weight:500;color:#000;">{{ ff.label }}</label>
                        </sc-if>
                        <sc-if value="{{ ff.hasHiddenLabel }}">
		          <label for="{{ ff.id }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">{{ ff.label }}</label>
                        </sc-if>
                        <sc-if value="{{ ff.isInput }}">
		          <input id="{{ ff.id }}" type="{{ ff.inputType }}" value="{{ ff.value }}" sc-camel-on-input="{{ ff.onInput }}" placeholder="{{ ff.placeholder }}" aria-describedby="{{ ff.describedBy }}" style="width:100%;height:44px;padding:0 12px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;">
                        </sc-if>
                        <sc-if value="{{ ff.isSelect }}">
		          <sc-raw-select id="{{ ff.id }}" value="{{ ff.value }}" sc-camel-on-change="{{ ff.onInput }}" aria-describedby="{{ ff.describedBy }}" style="width:100%;height:44px;padding:0 12px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;">
                            <sc-for list="{{ ff.options }}" as="fopt">
                              <option value="{{ fopt.value }}">{{ fopt.label }}</option>
                            </sc-for>
                          </sc-raw-select>
                        </sc-if>
                        <sc-if value="{{ ff.isTextarea }}">
		          <textarea id="{{ ff.id }}" value="{{ ff.value }}" sc-camel-on-input="{{ ff.onInput }}" placeholder="{{ ff.placeholder }}" aria-describedby="{{ ff.describedBy }}" rows="4" style="width:100%;padding:12px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;resize:vertical;"></textarea>
                        </sc-if>
                        <sc-if value="{{ ff.isRepeater }}">
                          <div style="display:flex;flex-direction:column;gap:12px;">
                            <sc-for list="{{ ff.repeaterRows }}" as="rr">
                              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end;padding:14px;border:1px solid #e5e5e5;border-radius:8px;background:#fafafa;">
                                <sc-for list="{{ rr.cells }}" as="rc">
                                  <label style="display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:500;color:#414454;">
                                    {{ rc.label }}
	                                    <sc-if value="{{ rc.isSelect }}"><sc-raw-select value="{{ rc.value }}" sc-camel-on-change="{{ rc.onInput }}" style="width:100%;height:40px;padding:0 10px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;"><sc-for list="{{ rc.options }}" as="ro"><option value="{{ ro.value }}">{{ ro.label }}</option></sc-for></sc-raw-select></sc-if>
	                                    <sc-if value="{{ rc.isInput }}"><input type="{{ rc.inputType }}" value="{{ rc.value }}" sc-camel-on-input="{{ rc.onInput }}" placeholder="{{ rc.placeholder }}" style="width:100%;height:40px;padding:0 10px;border:1px solid #a5a9be;border-radius:6px;background:#fff;font-size:14px;outline:none;"></sc-if>
                                  </label>
                                </sc-for>
                                <sc-if value="{{ rr.canRemove }}">
                                  <button type="button" sc-camel-on-click="{{ rr.onRemove }}" aria-label="Remove row" style="width:40px;height:40px;display:grid;place-items:center;border:1px solid #d1d5db;border-radius:6px;background:#fff;color:#6b7280;cursor:pointer;"><i class="ph ph-trash" aria-hidden="true"></i></button>
                                </sc-if>
                              </div>
                            </sc-for>
                            <button type="button" sc-camel-on-click="{{ ff.onAdd }}" style="align-self:flex-start;height:38px;padding:0 14px;border:1px solid #2B4BFF;background:#fff;color:#2B4BFF;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;"><i class="ph ph-plus" aria-hidden="true"></i> {{ ff.addLabel }}</button>
                          </div>
                        </sc-if>
                        <sc-if value="{{ ff.isToggle }}">
                          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border:1px solid #e5e5e5;border-radius:8px;">
                            <span style="font-size:14px;color:#414454;">{{ ff.help }}</span>
	                            <button type="button" role="switch" aria-label="{{ ff.toggleLabel }}" aria-checked="{{ ff.ariaChecked }}" sc-camel-on-click="{{ ff.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;flex-shrink:0;background:{{ ff.trackBg }};">
	                              <span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s;left:{{ ff.knobLeft }};"></span>
	                            </button>
                          </div>
                        </sc-if>
	                    <sc-if value="{{ ff.isFeatureToggle }}">
	                      <div style="display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:14px;align-items:start;padding:18px;border:1px solid #e5e5e5;border-radius:12px;background:#fff;">
	                        <span style="width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:#E9EDFF;color:#2B4BFF;"><i class="{{ ff.featureIcon }}" style="font-size:21px;"></i></span>
	                        <div style="min-width:0;">
	                          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:1px 0 6px;"><strong style="font-size:15px;color:#252525;">{{ ff.featureTitle }}</strong><span style="display:inline-flex;align-items:center;min-height:24px;padding:0 9px;border-radius:999px;background:#f1f2f6;color:#62697a;font-size:11px;font-weight:600;">{{ ff.featureBadge }}</span></div>
	                          <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">{{ ff.featureDescription }}</p>
	                        </div>
	                        <button type="button" role="switch" aria-label="{{ ff.toggleLabel }}" aria-checked="{{ ff.ariaChecked }}" sc-camel-on-click="{{ ff.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border:0;border-radius:999px;cursor:pointer;background:{{ ff.trackBg }};"><span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s;left:{{ ff.knobLeft }};"></span></button>
	                      </div>
	                    </sc-if>
                        <sc-if value="{{ ff.isChips }}">
                          <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <sc-for list="{{ ff.chips }}" as="ch">
		              <button type="button" sc-camel-on-click="{{ ch.onToggle }}" style="display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:999px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;border:1px solid {{ ch.border }};background:{{ ch.bg }};color:{{ ch.color }};">
                                <i class="{{ ch.icon }}" style="font-size:14px;"></i>{{ ch.label }}
		              </button>
                            </sc-for>
                          </div>
                        </sc-if>
                        <sc-if value="{{ ff.isSwatch }}">
                          <div style="display:flex;flex-wrap:wrap;gap:10px;">
                            <sc-for list="{{ ff.swatches }}" as="sw">
		              <button type="button" sc-camel-on-click="{{ sw.onPick }}" aria-label="Choose colour {{ sw.value }}" title="{{ sw.value }}" style="width:36px;height:36px;padding:0;border-radius:8px;cursor:pointer;display:grid;place-items:center;color:#fff;background:{{ sw.value }};box-shadow:0 0 0 {{ sw.ring }};">
                                <i class="ph-fill ph-check" style="font-size:16px;opacity:{{ sw.checkOpacity }};"></i>
		              </button>
                            </sc-for>
                          </div>
                        </sc-if>
	                        <sc-if value="{{ ff.isIconPick }}">
                          <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <sc-for list="{{ ff.icons }}" as="ic">
		              <button type="button" sc-camel-on-click="{{ ic.onPick }}" aria-label="Choose icon" style="width:40px;height:40px;padding:0;border-radius:8px;display:grid;place-items:center;cursor:pointer;border:1px solid {{ ic.border }};background:{{ ic.bg }};color:{{ ic.color }};">
                                <i class="{{ ic.icon }}" style="font-size:18px;"></i>
		              </button>
                            </sc-for>
                          </div>
	                        </sc-if>
	                        <sc-if value="{{ ff.isMedia }}">
	                          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:12px;border:1px solid #e5e5e5;border-radius:8px;background:#fff;">
	                            <sc-if value="{{ ff.mediaUrl }}">
	                              <img src="{{ ff.mediaUrl }}" alt="" style="width:88px;height:88px;object-fit:{{ ff.mediaFit }};border-radius:8px;border:1px solid #e5e5e5;">
	                            </sc-if>
	                            <sc-if value="{{ ff.mediaEmpty }}">
	                              <span style="width:88px;height:88px;display:grid;place-items:center;border-radius:8px;background:#f3f4f6;color:#6b7280;"><i class="ph ph-image" style="font-size:28px;"></i></span>
	                            </sc-if>
	                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
	                              <button type="button" sc-camel-on-click="{{ ff.onPick }}" style="height:38px;padding:0 14px;border:1px solid #2B4BFF;background:#fff;color:#2B4BFF;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;">{{ ff.mediaButton }}</button>
	                              <sc-if value="{{ ff.mediaUrl }}">
	                                <button type="button" sc-camel-on-click="{{ ff.onRemove }}" style="height:38px;padding:0 14px;border:1px solid #d1d5db;background:#fff;color:#6b7280;border-radius:6px;font-size:14px;cursor:pointer;">Remove</button>
	                              </sc-if>
	                            </div>
	                          </div>
	                        </sc-if>
	                        <sc-if value="{{ ff.isMediaDropzone }}">
	                          <div style="position:relative;width:100%;min-height:250px;border:1px dashed #a5a9be;border-radius:10px;background:#fafbff;overflow:hidden;">
	                            <sc-if value="{{ ff.mediaUrl }}"><img src="{{ ff.mediaUrl }}" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:{{ ff.mediaFit }};"></sc-if>
	                            <sc-if value="{{ ff.mediaUrl }}"><span style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,23,42,.08),rgba(15,23,42,.4));"></span></sc-if>
	                            <div style="position:relative;min-height:250px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:24px;text-align:center;color:{{ ff.mediaTextColor }};">
	                              <span style="width:48px;height:48px;display:grid;place-items:center;border-radius:12px;background:{{ ff.mediaIconBg }};color:#2B4BFF;"><i class="ph ph-image-square" style="font-size:24px;"></i></span>
	                              <strong style="font-size:15px;">{{ ff.mediaButton }}</strong>
	                              <span style="font-size:12px;line-height:1.5;">Select an image from the WordPress Media Library</span>
	                              <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;">
	                                <button type="button" sc-camel-on-click="{{ ff.onPick }}" style="height:38px;padding:0 14px;border:1px solid #2B4BFF;background:#fff;color:#2B4BFF;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;">{{ ff.mediaButton }}</button>
	                                <sc-if value="{{ ff.mediaUrl }}"><button type="button" sc-camel-on-click="{{ ff.onRemove }}" style="height:38px;padding:0 14px;border:1px solid #d1d5db;background:#fff;color:#525866;border-radius:7px;font-size:13px;cursor:pointer;">Remove</button></sc-if>
	                              </div>
	                            </div>
	                          </div>
	                        </sc-if>
	                      <sc-if value="{{ ff.isQrPreview }}">
	                        <div class="wcf-qr-preview-shell">
	                          <sc-if value="{{ ff.mediaUrl }}">
	                            <article class="wcf-qr-card" aria-label="QR table card preview">
	                              <img class="wcf-qr-card__background" src="{{ ff.qrBackground }}" alt="">
	                              <span class="wcf-qr-card__shade" aria-hidden="true"></span>
	                              <header class="wcf-qr-card__header">
	                                <span class="wcf-qr-card__brand"><img src="{{ ff.qrMark }}" alt="{{ ff.qrBrand }}"><small>{{ ff.qrBrand }}</small></span>
	                                <strong class="wcf-qr-card__name">{{ ff.qrName }}</strong>
	                              </header>
	                              <div class="wcf-qr-card__content">
	                                <strong class="wcf-qr-card__scan">Scan to order</strong>
	                                <span class="wcf-qr-card__code"><img src="{{ ff.mediaUrl }}" alt="QR code for {{ ff.qrName }}" width="256" height="256"></span>
	                                <p>Browse the full menu, order and pay from your phone.</p>
	                                <footer><i class="ph-fill ph-circle" aria-hidden="true"></i>{{ ff.qrUrl }}</footer>
	                              </div>
	                            </article>
	                          </sc-if>
	                          <sc-if value="{{ ff.mediaEmpty }}"><div class="wcf-qr-preview-empty"><i class="ph ph-qr-code" aria-hidden="true"></i><strong>Your table card will appear here</strong><span>Enter a complete menu URL to generate the preview.</span></div></sc-if>
	                        </div>
	                      </sc-if>
	                        <sc-if value="{{ ff.isNote }}">
	                          <div style="background:#f6f6f6;border-left:4px solid #2B4BFF;border-radius:8px;padding:16px;font-size:14px;color:#414454;line-height:1.55;">{{ ff.value }}</div>
	                        </sc-if>
	                        <sc-if value="{{ ff.isLink }}">
	                          <button type="button" sc-camel-on-click="{{ ff.onClick }}" style="justify-self:start;display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:0 16px;border:1px solid #2B4BFF;border-radius:7px;background:#fff;color:#2B4BFF;font-size:14px;font-weight:600;cursor:pointer;"><i class="ph ph-map-pin" aria-hidden="true"></i>{{ ff.label }}</button>
	                        </sc-if>
		                <sc-if value="{{ ff.hasHelp }}">
		          <span id="{{ ff.helpId }}" style="font-size:12px;color:#6b7280;">{{ ff.help }}</span>
                        </sc-if>
                      </div>
                    </sc-for>
                  </div>
                </div>
              </sc-for>
	              <sc-if value="{{ formFooterVisible }}">
	              <div style="display:flex;justify-content:flex-end;gap:12px;padding-bottom:24px;">
	                <button sc-camel-on-click="{{ goBack }}" style="height:44px;padding:0 24px;border:1px solid #6b7280;background:transparent;color:#414454;border-radius:6px;font-size:16px;font-weight:500;cursor:pointer;">Cancel</button>
	                <button sc-camel-on-click="{{ submitForm }}" style="height:44px;padding:0 24px;background:#2B4BFF;color:#fff;border:none;border-radius:6px;font-size:16px;font-weight:500;cursor:pointer;" style-hover="background:#1E38D6;">{{ formSubmitLabel }}</button>
	              </div>
	              </sc-if>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isIntegrations }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;">
              <sc-for list="{{ integrations }}" as="ig">
                <div style="background:#fff;border:1px solid #e6e6f0;border-radius:10px;padding:20px;display:flex;flex-direction:column;gap:12px;">
                  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                    <span style="width:48px;height:48px;border-radius:10px;display:grid;place-items:center;font-size:20px;font-weight:700;color:#fff;background:{{ ig.color }};">{{ ig.initial }}</span>
		    <button type="button" role="switch" aria-label="Toggle {{ ig.name }} integration" aria-checked="{{ ig.ariaChecked }}" sc-camel-on-click="{{ ig.onToggle }}" style="position:relative;display:inline-block;width:44px;height:24px;padding:0;border-radius:999px;cursor:pointer;background:{{ ig.trackBg }};">
                      <span style="position:absolute;top:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .2s;left:{{ ig.knobLeft }};"></span>
		    </button>
                  </div>
                  <div style="font-size:16px;font-weight:600;color:#000;">{{ ig.name }}</div>
                  <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.5;">{{ ig.description }}</p>
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:8px;">
                    <span style="display:inline-flex;align-items:center;gap:8px;font-size:13px;color:{{ ig.statusColor }};"><span style="width:8px;height:8px;border-radius:50%;background:{{ ig.statusColor }};"></span>{{ ig.status }}</span>
		    <button class="wcf-button" type="button" sc-camel-on-click="{{ ig.onConfigure }}" style="min-height:34px;padding:0 12px;border-radius:7px;font-size:14px;font-weight:500;cursor:pointer;">Configure</button>
                  </div>
                </div>
              </sc-for>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isShortcodes }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;">
              <sc-for list="{{ shortcodes }}" as="sc">
                <div style="background:#fff;border:1px solid #e6e6f0;border-radius:10px;padding:20px;display:flex;flex-direction:column;gap:12px;">
                  <div style="font-size:16px;font-weight:600;color:#000;">{{ sc.name }}</div>
                  <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.5;">{{ sc.description }}</p>
                  <div style="display:flex;align-items:center;gap:8px;background:#f6f6f6;border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;">
                    <code style="flex:1;font-size:13px;color:#414454;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ sc.code }}</code>
		          <button type="button" sc-camel-on-click="{{ sc.onCopy }}" title="Copy shortcode" aria-label="Copy {{ sc.name }} shortcode" style="width:30px;height:30px;display:grid;place-items:center;border-radius:6px;background:#fff;border:1px solid #e5e5e5;color:#414454;cursor:pointer;" style-hover="border-color:#2B4BFF;color:#2B4BFF;"><i class="ph ph-copy" aria-hidden="true" style="font-size:15px;"></i></button>
                  </div>
                </div>
              </sc-for>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isAbout }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="max-width:880px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:40px;">
              <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;">
                <span style="width:56px;height:56px;border-radius:12px;overflow:hidden;display:grid;place-items:center;"><img src="<?php echo esc_url( add_query_arg( 'ver', WOWRESTRO_VERSION, WOWRESTRO_URL . 'assets/images/wowrestro-logo.png' ) ); ?>" alt="WowRestro" style="width:56px;height:56px;object-fit:cover;display:block;"></span>
                <div>
                  <div style="font-size:24px;font-weight:700;color:#000;">WowRestro</div>
                  <div style="font-size:14px;color:#6b7280;">Restaurant ordering and kitchen operations for WooCommerce - version {{ version }}</div>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <sc-for list="{{ aboutCards }}" as="ac">
                  <div style="border:1px solid #e5e5e5;border-radius:10px;padding:20px;display:flex;flex-direction:column;gap:8px;">
                    <i class="{{ ac.icon }}" style="font-size:24px;color:#2B4BFF;"></i>
                    <div style="font-size:15px;font-weight:600;color:#000;">{{ ac.title }}</div>
                    <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.5;">{{ ac.description }}</p>
                  </div>
                </sc-for>
              </div>
            </div>
          </div>
        </sc-if>

        <sc-if value="{{ isProLocked }}">
          <div style="width:100%;margin:0 auto;padding:16px;">
            <div style="max-width:720px;margin:24px auto;background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:40px;display:flex;flex-direction:column;align-items:center;gap:16px;text-align:center;">
              <span style="width:64px;height:64px;border-radius:50%;background:#E9EDFF;display:grid;place-items:center;color:#2B4BFF;"><i class="ph-fill ph-crown-simple" style="font-size:30px;"></i></span>
              <div style="font-size:20px;font-weight:700;color:#000;">{{ pageTitle }} is a Pro feature</div>
              <p style="margin:0;max-width:460px;font-size:14px;color:#6b7280;line-height:1.6;">Get access to advanced features, detailed analytics, and priority support. Upgrade now to enhance your booking experience!</p>
              <div style="display:flex;gap:12px;">
                <button style="height:44px;padding:0 20px;border:1px solid #6b7280;background:transparent;color:#414454;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;">Learn More</button>
                <button style="height:44px;padding:0 20px;background:#f0b100;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;">Upgrade Now</button>
              </div>
            </div>
          </div>
        </sc-if>

      </main>
    </div>
  </div>

  <sc-if value="{{ qrPreviewVisible }}">
    <div class="wcf-qr-modal" sc-camel-on-click="{{ qrPreviewClose }}">
      <section class="wcf-qr-modal__dialog" role="dialog" aria-modal="true" aria-label="QR table card preview" sc-camel-on-click="{{ stopPropagation }}">
        <header class="wcf-qr-modal__header"><strong>{{ qrPreviewName }}</strong><button type="button" sc-camel-on-click="{{ qrPreviewClose }}" aria-label="Close QR preview"><i class="ph ph-x" aria-hidden="true"></i></button></header>
        <article class="wcf-qr-card wcf-qr-card--modal">
          <img class="wcf-qr-card__background" src="{{ qrPreviewBackground }}" alt="">
          <span class="wcf-qr-card__shade" aria-hidden="true"></span>
          <header class="wcf-qr-card__header">
			<span class="wcf-qr-card__brand"><img src="{{ qrPreviewMark }}" alt="{{ qrPreviewBrand }}"><small>{{ qrPreviewBrand }}</small></span>
            <strong class="wcf-qr-card__name">{{ qrPreviewName }}</strong>
          </header>
          <div class="wcf-qr-card__content">
            <strong class="wcf-qr-card__scan">Scan to order</strong>
            <span class="wcf-qr-card__code"><img src="{{ qrPreviewCode }}" alt="QR code for {{ qrPreviewName }}" width="512" height="512"></span>
            <p>Browse the full menu, order and pay from your phone.</p>
            <footer><i class="ph-fill ph-circle" aria-hidden="true"></i>{{ qrPreviewUrl }}</footer>
          </div>
        </article>
      </section>
    </div>
  </sc-if>

  <sc-if value="{{ confirmVisible }}">
    <div sc-camel-on-click="{{ confirmCancel }}" style="position:fixed;inset:0;z-index:100001;background:rgba(29,34,43,.45);display:grid;place-items:center;padding:24px;">
      <div sc-camel-on-click="{{ stopPropagation }}" style="width:100%;max-width:420px;background:#fff;border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.25);padding:28px;display:flex;flex-direction:column;gap:14px;animation:wcfToast .18s ease-out;">
        <span style="width:48px;height:48px;border-radius:50%;background:#fee2e2;color:#ef4444;display:grid;place-items:center;"><i class="ph ph-trash" style="font-size:22px;"></i></span>
        <div style="font-size:18px;font-weight:600;color:#000;">{{ confirmTitle }}</div>
        <p style="margin:0;font-size:14px;line-height:1.55;color:#6b7280;">{{ confirmMessage }}</p>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:6px;">
          <button sc-camel-on-click="{{ confirmCancel }}" style="height:42px;padding:0 20px;border:1px solid #a5a9be;background:#fff;color:#414454;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;">Cancel</button>
          <button sc-camel-on-click="{{ confirmAccept }}" style="height:42px;padding:0 20px;background:#ef4444;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer;" style-hover="background:#c81e1e;">Delete</button>
        </div>
      </div>
    </div>
  </sc-if>

  <sc-if value="{{ toastVisible }}">
    <div style="position:fixed;right:24px;bottom:24px;z-index:100000;display:flex;align-items:center;gap:12px;background:#1d222b;color:#fff;padding:14px 18px;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.25);font-size:14px;animation:wcfToast .2s ease-out;">
      <i class="ph-fill ph-check-circle" style="font-size:20px;color:#10b981;"></i>{{ toastMessage }}
    </div>
  </sc-if>

</div>

</x-dc>
