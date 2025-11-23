/**
 * Keiste Solar Analysis - Utility Functions
 * Helper functions for energy estimates and calculations
 * Version: 1.0.0
 */

(function () {
    'use strict';

    const DAY_POWER_AVG = 1.85; // kWh/day per 400W panel
    const DAYS_IN_YR = 365.4;

    // ===== ENERGY PRODUCTION ESTIMATION =====
    window.estimateEnergyProduction = function (panelCount) {
        const solarConfigs = window.solarConfigs || [];
        
        if (!solarConfigs || solarConfigs.length === 0) {
            // Fallback calculation
            return panelCount * DAY_POWER_AVG * DAYS_IN_YR;
        }

        // Find the closest configurations
        let lowerConfig = null;
        let upperConfig = null;

        for (const config of solarConfigs) {
            if (config.panelsCount <= panelCount) {
                lowerConfig = config;
            }
            if (config.panelsCount >= panelCount && !upperConfig) {
                upperConfig = config;
            }
        }

        // If exact match found
        if (lowerConfig && lowerConfig.panelsCount === panelCount) {
            return lowerConfig.yearlyEnergyDcKwh;
        }

        // If panel count is less than smallest configuration
        if (!lowerConfig) {
            const smallestConfig = solarConfigs[0];
            return (panelCount / smallestConfig.panelsCount) * smallestConfig.yearlyEnergyDcKwh;
        }

        // If panel count is more than largest configuration
        if (!upperConfig) {
            const largestConfig = solarConfigs[solarConfigs.length - 1];
            return (panelCount / largestConfig.panelsCount) * largestConfig.yearlyEnergyDcKwh;
        }

        // Interpolate between configurations
        const panelDiff = upperConfig.panelsCount - lowerConfig.panelsCount;
        const energyDiff = upperConfig.yearlyEnergyDcKwh - lowerConfig.yearlyEnergyDcKwh;
        const ratio = (panelCount - lowerConfig.panelsCount) / panelDiff;
        
        return lowerConfig.yearlyEnergyDcKwh + (energyDiff * ratio);
    };

    // ===== INSTALLATION COST CALCULATION =====
    window.calculateInstallationCost = function (yearlyEnergyKwh) {
        // Convert yearly energy (kWh) to installed capacity (kWp)
        // Using average solar irradiance: 1 kWp produces ~1000 kWh/year in Ireland
        const installedCapacityKwp = yearlyEnergyKwh / 1000;

        // Apply sliding scale pricing
        let totalCost = 0;
        
        if (installedCapacityKwp <= 100) {
            // 0-100 kWp: €1,500 per kWp
            totalCost = installedCapacityKwp * 1500;
        } else if (installedCapacityKwp <= 250) {
            // 100-250 kWp: €1,300 per kWp
            // First 100 kWp at €1,500, remainder at €1,300
            totalCost = (100 * 1500) + ((installedCapacityKwp - 100) * 1300);
        } else {
            // 250+ kWp: €1,100 per kWp
            // First 100 kWp at €1,500, next 150 kWp at €1,300, remainder at €1,100
            totalCost = (100 * 1500) + (150 * 1300) + ((installedCapacityKwp - 250) * 1100);
        }

        return totalCost;
    };

    // ===== CURRENCY FORMATTING =====
    window.formatCurrency = function (value, decimals = 0) {
        const num = Number(value) || 0;
        return (window.CURRENCY_SYMBOL || '') + num.toLocaleString('en-IE', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    };

    // ===== PANEL COUNT GETTER =====
    function getPanelCount() {
        const range = document.querySelector('input[type="range"]#panelCount');
        if (range) return parseInt(range.value, 10) || 0;
        
        const disp = document.getElementById('panelCountValue') || 
                     document.getElementById('panelCountDisplay');
        if (disp) return parseInt(disp.textContent.trim(), 10) || 0;
        
        return 0;
    }

    // ===== COST UPDATE HANDLER =====
    function updateCosts() {
        try {
            const SEAI_GRANT_RATE = 0.30;
            const SEAI_GRANT_CAP = 162000;
            const ACA_RATE = 0.125;

            const panelCount = getPanelCount();
            
            // Determine yearly energy
            const yearlyEnergy = window.estimateEnergyProduction(panelCount);

            // Installation cost
            const installCost = window.calculateInstallationCost(yearlyEnergy);

            const inclGrant = !!document.getElementById('inclGrant')?.checked;
            const inclACA = !!document.getElementById('inclACA')?.checked;

            const seaiGrant = inclGrant ? Math.min(installCost * SEAI_GRANT_RATE, SEAI_GRANT_CAP) : 0;
            const acaGrant = inclACA ? (installCost * ACA_RATE) : 0;
            const totalGrant = seaiGrant + acaGrant;

            const netCost = Math.max(0, installCost - totalGrant);

            // Update DOM elements
            const idsToSet = [
                'installCost', 'installationCost', 'netCost', 'grant',
                'panelCountValue', 'panelCountDisplay', 'panelCount'
            ];

            idsToSet.forEach(id => {
                document.querySelectorAll('#' + id).forEach(el => {
                    if (!el) return;
                    
                    // Don't overwrite form inputs
                    if (el.tagName && el.tagName.toUpperCase() === 'INPUT') return;

                    switch (id) {
                        case 'installCost':
                        case 'installationCost':
                            el.textContent = Math.round(installCost).toLocaleString();
                            break;
                        case 'netCost':
                            el.textContent = window.formatCurrency(netCost, 0);
                            break;
                        case 'grant':
                            el.textContent = window.formatCurrency(totalGrant, 0);
                            break;
                        case 'panelCountValue':
                        case 'panelCountDisplay':
                        case 'panelCount':
                            el.textContent = String(panelCount);
                            break;
                    }
                });
            });

            // Trigger chart updates
            if (typeof window.updateEnergyChart === 'function') {
                window.updateEnergyChart();
            }

            if (typeof window.calculateBreakEvenDataSimple === 'function' && window.breakEvenChart) {
                try {
                    const cfg = { panelsCount: panelCount, yearlyEnergyDcKwh: yearlyEnergy };
                    const be = window.calculateBreakEvenDataSimple(cfg);
                    
                    if (be && be.savings && window.breakEvenChart.data &&
                        window.breakEvenChart.data.datasets &&
                        window.breakEvenChart.data.datasets[0]) {
                        
                        window.breakEvenChart.data.datasets[0].data = be.savings;
                        window.breakEvenChart.data.datasets[0].label = `${panelCount} Panels`;
                        window.breakEvenChart.update();
                    }
                } catch (e) {
                    console.error('Chart update error', e);
                }
            }
        } catch (err) {
            console.error('updateCosts error', err);
        }
    }

    // ===== EVENT ATTACHMENT =====
    function attachHooks() {
        const range = document.querySelector('input[type="range"]#panelCount');
        if (range) {
            range.addEventListener('input', () => {
                if (typeof window.calculateROI === 'function') {
                    window.calculateROI();
                }
            });
            range.addEventListener('change', () => {
                if (typeof window.calculateROI === 'function') {
                    window.calculateROI();
                }
            });
        }

        ['inclGrant', 'inclACA', 'inclLoan'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => {
                    if (typeof window.calculateROI === 'function') {
                        window.calculateROI();
                    }
                });
            }
        });

        ['electricityRate', 'exportRate', 'degradation', 'loanApr', 'loanTerm'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', () => {
                    if (typeof window.calculateROI === 'function') {
                        window.calculateROI();
                    }
                });
            }
        });

        // Run initial sync
        if (typeof window.calculateROI === 'function') {
            window.calculateROI();
        }
    }

    // ===== INITIALIZATION =====
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(attachHooks, 10);
    } else {
        document.addEventListener('DOMContentLoaded', attachHooks);
    }

    // Expose functions
    window.updateCosts = updateCosts;
    window.attachHooks = attachHooks;

})();
