/**
 * Keiste Solar Analysis - Chart Utilities
 * Chart.js integration for energy and break-even visualizations
 * Version: 1.0.0
 */

(function () {
    'use strict';

    const SOLAR_PANEL_DEGRADATION = 0.005;
    const YRS_OF_SYSTEM = 25;
    const DAY_POWER_AVG = 1.85;
    const DAYS_IN_YR = 365.4;

    // ===== HELPER FUNCTIONS =====
    function formatCurrency(value, decimals = 0) {
        const num = Number(value) || 0;
        return (window.CURRENCY_SYMBOL || '') + num.toLocaleString('en-IE', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }

    // ===== BREAK-EVEN CHART =====
    function calculateBreakEvenDataSimple(config) {
        const yearlyEnergy = config.yearlyEnergyDcKwh;
        const panelCount = config.panelsCount;
        const electricityRate = parseFloat(document.getElementById('electricityRate')?.value) || 0.45;

        const exportRate = (() => {
            const v = document.getElementById('exportRate')?.value;
            const p = parseFloat(String(v || '').replace(/[^0-9.\-]/g, ''));
            return Number.isFinite(p) ? (p / 100) : 0.4;
        })();

        const inclGrant = document.getElementById('inclGrant')?.checked;
        const inclACA = document.getElementById('inclACA')?.checked;
        const inclLoan = document.getElementById('inclLoan')?.checked;
        const degradation = 0.005;

        // Compute installation cost
        const installedCapacityKwp = yearlyEnergy / 1000;
        let computedInstallCost = 0;
        if (installedCapacityKwp <= 100) {
            computedInstallCost = installedCapacityKwp * 1500;
        } else if (installedCapacityKwp <= 250) {
            computedInstallCost = (100 * 1500) + ((installedCapacityKwp - 100) * 1300);
        } else {
            computedInstallCost = (100 * 1500) + (150 * 1300) + ((installedCapacityKwp - 250) * 1100);
        }

        const seaiGrant = inclGrant ? Math.min(computedInstallCost * 0.3, 162000) : 0;
        const acaGrant = inclACA ? (computedInstallCost * 0.125) : 0;
        const totalGrant = seaiGrant + acaGrant;
        const totalCost = computedInstallCost - totalGrant;

        const years = Array.from({ length: 25 }, (_, i) => i);
        const savings = years.map(year => {
            const yearDegradation = Math.pow(1 - degradation, year);
            const yearlyEnergyProduction = yearlyEnergy * yearDegradation;
            const selfConsumedEnergy = yearlyEnergyProduction * (1 - exportRate);
            const exportedEnergy = yearlyEnergyProduction * exportRate;
            const yearlySaving = (selfConsumedEnergy * electricityRate) + (exportedEnergy * exportRate);

            const totalSaving = year === 0 ? 0 : years
                .slice(1, year + 1)
                .reduce((acc, y) => {
                    const yDegradation = Math.pow(1 - degradation, y);
                    const yEnergyProduction = yearlyEnergy * yDegradation;
                    const ySelfConsumed = yEnergyProduction * (1 - exportRate);
                    const yExported = yEnergyProduction * exportRate;
                    return acc + (ySelfConsumed * electricityRate) + (yExported * exportRate);
                }, 0);

            return totalSaving - totalCost;
        });

        return {
            cost: totalCost,
            savings: savings,
            breakEvenYear: savings.findIndex(saving => saving >= 0)
        };
    }

    // ===== INITIALIZE BREAK-EVEN CHART =====
    function initBreakEvenChart() {
        const canvas = document.getElementById('breakEvenChart');
        if (!canvas || !window.Chart) return null;

        const ctx = canvas.getContext('2d');
        
        // Initialize with zero data - will be populated when user enters bill
        const data = {
            cost: 0,
            savings: Array(25).fill(0),
            breakEvenYear: -1
        };

        return new Chart(ctx, {
            type: 'line',
            data: {
                labels: Array.from({ length: 25 }, (_, i) => `Year ${i}`),
                datasets: [{
                    label: '0 Panels',
                    data: data.savings,
                    borderColor: 'rgba(42, 157, 143, 1)',
                    backgroundColor: 'rgba(42, 157, 143, 0.08)',
                    fill: false,
                    tension: 0.3,
                    pointRadius: window.innerWidth > 600 ? 2 : 0,
                    pointHoverRadius: 4,
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: window.innerWidth > 600,
                        text: window.innerWidth > 600 ? 'Investment Return Over Time' : '',
                        font: {
                            size: window.innerWidth > 600 ? 16 : 12,
                            family: "'Inter', sans-serif",
                            weight: '600'
                        }
                    },
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const value = context.raw;
                                return value >= 0
                                    ? `Profit: ${formatCurrency(value, 0)}`
                                    : `Investment: ${formatCurrency(Math.abs(value), 0)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        title: {
                            display: window.innerWidth > 600,
                            text: window.innerWidth > 600 ? 'Net Financial Position (' + (window.CURRENCY_SYMBOL || '') + ')' : '',
                            font: {
                                weight: '500',
                                size: window.innerWidth > 600 ? 14 : 11
                            }
                        },
                        ticks: {
                            callback: function (value) {
                                return (window.CURRENCY_SYMBOL || '') + value.toLocaleString('en-IE', { maximumFractionDigits: 0 });
                            },
                            font: { size: window.innerWidth > 600 ? 13 : 10 }
                        },
                        grid: { display: window.innerWidth > 600 }
                    },
                    x: {
                        title: {
                            display: window.innerWidth > 600,
                            text: window.innerWidth > 600 ? 'Years' : '',
                            font: {
                                weight: '500',
                                size: window.innerWidth > 600 ? 14 : 11
                            }
                        },
                        ticks: {
                            font: { size: window.innerWidth > 600 ? 13 : 10 },
                            autoSkip: true,
                            maxTicksLimit: window.innerWidth > 600 ? 12 : 6
                        },
                        grid: { display: window.innerWidth > 600 }
                    }
                }
            }
        });
    }

    // ===== INITIALIZE ENERGY CHART =====
    function initEnergyChart() {
        const canvas = document.getElementById('energyChart');
        if (!canvas || !window.Chart) return null;

        const ctx = canvas.getContext('2d');

        return new Chart(ctx, {
            type: 'bar',
            data: {
                labels: Array.from({ length: 25 }, (_, i) => `Year ${i}`),
                datasets: [{
                    label: 'Projected Annual Energy Production (kWh)',
                    data: Array.from({ length: 25 }, () => 0),
                    backgroundColor: 'rgba(40, 167, 69, 0.6)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Annual Energy Production (kWh)'
                        }
                    },
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: window.innerWidth > 600 ? 12 : 6
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Projected Energy Production (per year)'
                    }
                }
            }
        });
    }

    // ===== UPDATE ENERGY CHART =====
    window.updateEnergyChart = function () {
        try {
            if (!window.energyChart) return;
            
            const panelCount = parseInt(document.getElementById('panelCount')?.value || 4);
            const yearlyEnergy = typeof estimateEnergyProduction === 'function'
                ? estimateEnergyProduction(panelCount)
                : (panelCount * DAY_POWER_AVG * DAYS_IN_YR);
            
            const degradation = parseFloat(document.getElementById('degradation')?.value) / 100 || 0.005;
            const years = Array.from({ length: 25 }, (_, i) => i);
            
            const data = years.map(year => {
                return yearlyEnergy * Math.pow(1 - degradation, year);
            });

            window.energyChart.data.datasets[0].data = data;
            window.energyChart.data.datasets[0].label = `${panelCount} panels — Annual Production (kWh)`;
            window.energyChart.update();
        } catch (e) {
            console.error('updateEnergyChart error', e);
        }
    };

    // ===== UPDATE BREAK-EVEN CHART =====
    window.updateBreakEvenChart = function (state, figs) {
        console.log('updateBreakEvenChart in charts.js called', { state, figs, hasChart: !!window.breakEvenChart });
        
        if (!window.breakEvenChart) {
            console.warn('No breakEvenChart available');
            return;
        }

        try {
            // On page load with no bill, show zero baseline
            if (!state.billMonthly || state.billMonthly === 0) {
                console.log('Setting chart to zero (no bill)');

                if (window.breakEvenChart.data && window.breakEvenChart.data.datasets && window.breakEvenChart.data.datasets[0]) {
                    window.breakEvenChart.data.datasets[0].data = Array(25).fill(0);
                    window.breakEvenChart.data.datasets[0].label = '0 Panels';
                    window.breakEvenChart.update('none');
                }
                return;
            }

            if (typeof calculateBreakEvenDataSimple !== 'function') return;
            
            const cfg = {
                panelsCount: state.panels,
                yearlyEnergyDcKwh: figs.yearlyEnergyKWh || (state.panels * DAY_POWER_AVG * DAYS_IN_YR)
            };
            
            const be = calculateBreakEvenDataSimple(cfg);
            
            if (be && be.savings && window.breakEvenChart.data &&
                window.breakEvenChart.data.datasets &&
                window.breakEvenChart.data.datasets[0]) {
                
                window.breakEvenChart.data.datasets[0].data = [...be.savings];
                window.breakEvenChart.data.datasets[0].label = `${state.panels} Panels`;
                window.breakEvenChart.update('active');
            }
        } catch (e) {
            console.error('updateBreakEvenChart error', e);
        }
    };

    // ===== INITIALIZATION =====
    document.addEventListener('DOMContentLoaded', function () {
        if (window.Chart) {
            window.breakEvenChart = initBreakEvenChart();
            window.energyChart = initEnergyChart();
            
            // Don't update charts on initial load - keep values at zero
            // if (window.energyChart) {
            //     setTimeout(window.updateEnergyChart, 100);
            // }
        }
    });

    // Expose functions
    window.calculateBreakEvenDataSimple = calculateBreakEvenDataSimple;
    window.initBreakEvenChart = initBreakEvenChart;
    window.initEnergyChart = initEnergyChart;

})();
