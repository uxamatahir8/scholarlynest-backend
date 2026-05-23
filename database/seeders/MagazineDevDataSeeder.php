<?php

namespace Database\Seeders;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\Article;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class MagazineDevDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Temporarily disable foreign key constraints
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        // 2. Truncate historical tables to ensure absolute clean seed
        $tables = [
            'article_tag',
            'tags',
            'article_share_clicks',
            'articles',
            'magazine_pages',
            'magazines',
        ];

        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        // Re-enable foreign key constraints
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        // Start transaction for DML inserts
        DB::beginTransaction();

        try {
            // Fetch default author user (e.g. admin or fallback to first user)
            $authorUser = User::where('email', 'admin@scholarlynest.com')->first() 
                ?? User::first();

            if (!$authorUser) {
                // If no user exists, create a default administrator
                $authorUser = User::create([
                    'name' => 'Dr. Evelyn Reed (Admin)',
                    'email' => 'admin@scholarlynest.com',
                    'password' => bcrypt('admin12345'),
                    'email_verified_at' => now(),
                ]);
            }

            // High-quality Unsplash academic/scientific stock placeholders
            $unsplashImages = [
                'lab_glassware' => 'https://images.unsplash.com/photo-1507413245164-6160d8298b31?auto=format&fit=crop&w=800&q=80',
                'microscope' => 'https://images.unsplash.com/photo-1532094349884-543bc11b234d?auto=format&fit=crop&w=800&q=80',
                'chart_dashboard' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=800&q=80',
                'test_tubes' => 'https://images.unsplash.com/photo-1532187643603-ba119ca4109e?auto=format&fit=crop&w=800&q=80',
                'neural_ai' => 'https://images.unsplash.com/photo-1507668077129-56e32842fceb?auto=format&fit=crop&w=800&q=80',
                'engineering_workspace' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=800&q=80',
                'biomedical_eq' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=800&q=80',
                'cardiology' => 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=800&q=80',
                'agriculture_field' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=800&q=80',
                'digital_grid' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=800&q=80',
            ];

            // 20 Elite Academic Publications Data Spec
            $magazinesData = [
                [
                    'title' => 'International Journal of Applied Sciences',
                    'description' => 'A monthly peer-reviewed journal highlighting multidisciplinary advancements in engineering physics, sensor telemetry, and applied systems computation.',
                    'about_text' => 'Established in 2018, the International Journal of Applied Sciences acts as a bridge between foundational laboratory experiments and operational industrial deployments. Our editorial team selects high-impact findings with rigorous statistical telemetry.',
                    'tags' => ['Sensor Networks', 'Applied Physics', 'Material Science', 'Chemical Analytics', 'Aero-dynamics', 'Signal Processing'],
                    'pages' => [
                        'Submission Protocols' => 'Guidelines for authors submitting mathematical modeling scripts, sensor calibrations, and raw experimental data logs.',
                        'Editorial Board' => 'Our board consists of 14 international professors spanning aerospace, mechanical, and optical physics fields.',
                        'Peer Review Standards' => 'All articles undergo double-blind peer review with independent reviewers verifying code correctness and telemetry data.',
                        'Open Access Policies' => 'Full access is provided to all papers under the Creative Commons BY-NC 4.0 license framework.',
                        'Archival Coverage' => 'Details about our integration with CrossRef, Scopus, and international indexes.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Optimization Protocols for Low-Latency Distributed Telemetry Networks',
                            'abstract' => 'We present a framework for reducing packet loss in sub-orbital telemetry routers using machine-learned bandwidth allocation. Results verify a 32% latency drop.',
                            'topics' => ['networking', 'telemetry']
                        ],
                        [
                            'title' => 'Thermal Dissipation Dynamics in Nanostructured Carbon Interfaces',
                            'abstract' => 'This paper reports experimental results on thermal routing under high electrical loads, validating nanostructured interfaces as effective conductors.',
                            'topics' => ['physics', 'materials']
                        ],
                        [
                            'title' => 'Aerodynamic Optimization of Sub-Scale Fixed Wing Systems',
                            'abstract' => 'Investigating airfoils under low Reynolds number regimes to improve endurance margins in sub-scale atmospheric gliders.',
                            'topics' => ['aero', 'physics']
                        ]
                    ]
                ],
                [
                    'title' => 'Pakistan Agricultural Economics Review',
                    'description' => 'Dedicated to resource economics, sustainable crop productivity telemetry, water governance, and rural financial policies across regional farm sectors.',
                    'about_text' => 'The Pakistan Agricultural Economics Review publishes original empirical studies analyzing macro agricultural trends, water resource scarcity solutions, and micro-financing outcomes in regional crop belts.',
                    'tags' => ['Water Resource', 'Agri-Tech', 'Resource Economics', 'Crop Yields', 'Rural Finance', 'Sustainable Farming'],
                    'pages' => [
                        'Data & Telemetry Archiving' => 'Specific formats required for farm yield surveys, regional meteorological logs, and spatial mapping files.',
                        'Editorial Council' => 'Led by national directors in agricultural engineering, trade policy, and agrarian reform.',
                        'Manuscript Guidelines' => 'Abstract length must not exceed 250 words, with strict adherence to Harvard citation standards.',
                        'Review Policies' => 'Average review turnaround is 6 weeks. Two peer experts evaluate statistical modeling validity.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Irrigation Water Scarcity and Agricultural Output Optimization in the Indus Basin',
                            'abstract' => 'Analyzing Indus Basin canal water flows and groundwater pumping metrics to model optimal farm yields during drought seasons.',
                            'topics' => ['water', 'economics']
                        ],
                        [
                            'title' => 'Micro-Finance Access and Crop Yield Sustainability in Punjab Farms',
                            'abstract' => 'Evaluating micro-loan disbursement structures against wheat and rice production outputs across 14 rural districts.',
                            'topics' => ['economics', 'farming']
                        ],
                        [
                            'title' => 'Automated Drip Irrigation Telemetry in Semi-Arid Agricultural Regions',
                            'abstract' => 'Applying real-time soil moisture sensor grids to regulate water release profiles, achieving a 45% water savings.',
                            'topics' => ['agri-tech', 'water']
                        ],
                        [
                            'title' => 'Evaluating Crop Rotation Telemetry Under Climate Shift Scenarios',
                            'abstract' => 'A 10-year longitudinal study mapping changing monsoon patterns against optimal sowing schedules for cash crops.',
                            'topics' => ['farming', 'agri-tech']
                        ]
                    ]
                ],
                [
                    'title' => 'Scholarly Review of Bio-Engineering',
                    'description' => 'Reviewing next-generation cellular sequencing, gene editing safety validation, tissue synthesis models, and robotic surgical enhancements.',
                    'about_text' => 'Scholarly Review of Bio-Engineering highlights advanced biotechnological engineering methodologies. We merge research from genomics, synthetic cellular biology, and medical robotic systems to improve clinical diagnostics.',
                    'tags' => ['Gene Editing', 'Cellular Synthesis', 'Robotics', 'Genomics', 'Tissue Engineering', 'Diagnostics'],
                    'pages' => [
                        'Bio-Safety Policies' => 'Mandatory disclosure statements for genetic research, biosafety level certifications, and animal testing approvals.',
                        'Board of Editors' => 'Distinguished investigators in biomaterials, gene regulation, and surgical robotics.',
                        'Peer Assessment Schema' => 'Three independent specialists review each manuscript. Telemetry scripts must be publicly hosted.',
                        'Intellectual Property' => 'Copyright guidelines regarding proprietary cell line structures and hardware designs.',
                        'Open Access Policy' => 'Immediate open-access dissemination for all clinical telemetry logs.'
                    ],
                    'articles' => [
                        [
                            'title' => 'CRISPR Gene Editing Verification via Deep Sequence Telemetry',
                            'abstract' => 'Deploying machine learning models to analyze off-target genomic cleavage sites, achieving a 99.4% detection accuracy.',
                            'topics' => ['gene', 'genomics']
                        ],
                        [
                            'title' => 'Tissue Engineering Scaffolds Synthesized from Biodegradable Polymers',
                            'abstract' => 'Evaluating cellular growth rates and structural integrity of 3D-printed synthetic polymer scaffolds in lab environments.',
                            'topics' => ['tissue', 'polymers']
                        ],
                        [
                            'title' => 'Robotic Surgical Precision Enhancement Using Neural Control Interfaces',
                            'abstract' => 'Introducing a closed-loop neural controller that filters micro-tremors during robotic microsurgical operations.',
                            'topics' => ['robotics', 'control']
                        ]
                    ]
                ],
                [
                    'title' => 'Global Environmental Policy and Law',
                    'description' => 'Examining climate change mitigation statutes, biodiversity conservation regulations, and international treaty enforcement frameworks.',
                    'about_text' => 'This journal offers a platform for legal scholars, policy analysts, and conservation scientists to debate structural policy frameworks, international carbon credit laws, and sustainable resource strategies.',
                    'tags' => ['Environmental Law', 'Carbon Credits', 'Treaty Policy', 'Biodiversity', 'Climate Mitigation', 'Resource Management'],
                    'pages' => [
                        'Legal Style Guide' => 'Citations must adhere to the Bluebook formatting standard for legal briefs, treaties, and court cases.',
                        'Advisory Board' => 'Composed of global policy advisors, environmental lawyers, and sustainability researchers.',
                        'Peer Validation Protocol' => 'Double-blind reviews evaluate legal reasoning, empirical policy modeling, and case study relevance.',
                        'Author Responsibilities' => 'Submission requirements regarding ethical data procurement and conflicts of interest declarations.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Carbon Credit Ledger Compliance Under the Paris Climate Accord',
                            'abstract' => 'Evaluating international carbon market transactions and double-counting prevention mechanisms across regional registries.',
                            'topics' => ['carbon', 'treaty']
                        ],
                        [
                            'title' => 'Legal Frameworks for Marine Conservation in Extra-Territorial Waters',
                            'abstract' => 'Analyzing enforcement gaps in global ocean treaties concerning industrial fishing and deep-sea mining restrictions.',
                            'topics' => ['marine', 'law']
                        ],
                        [
                            'title' => 'Cross-Border Biodiversity Corridors: Regulatory Coordination Challenges',
                            'abstract' => 'Examining policy alignment constraints between neighboring sovereign states managing shared ecological zones.',
                            'topics' => ['biodiversity', 'law']
                        ],
                        [
                            'title' => 'Socio-Economic Impact of Regional Deforestation Penalties',
                            'abstract' => 'A microeconomic evaluation of strict forestry law enforcement on local communities in South American woodland sectors.',
                            'topics' => ['law', 'defom']
                        ]
                    ]
                ],
                [
                    'title' => 'Journal of Advanced Quantum Computing',
                    'description' => 'Focusing on qubit coherence optimization, quantum fault tolerance codes, cryo-CMOS integration, and superconducting qubit architectures.',
                    'about_text' => 'The Journal of Advanced Quantum Computing publishes breakthroughs in quantum hardware engineering, algorithm complexity, cryostatic controller scaling, and error mitigation algorithms.',
                    'tags' => ['Quantum Hardware', 'Qubit Coherence', 'Fault Tolerance', 'Cryogenics', 'Superconductors', 'Algorithms'],
                    'pages' => [
                        'Hardware Telemetry Specs' => 'Guidelines for reporting qubit gate fidelities, cryogenic temperatures, and microwave noise levels.',
                        'Scientific Board' => 'Physicists and computer engineers specialized in quantum information science.',
                        'Review Framework' => 'Submissions must include noise calibration datasets to allow peer replication of experimental runs.',
                        'Ethics & Security Statement' => 'Evaluation of algorithmic cryptography impacts and post-quantum security readiness.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Active Cryo-CMOS Controllers for Superconducting Qubit Matrices',
                            'abstract' => 'Designing integrated circuits operating at 4 Kelvin that reduce control wiring bottlenecks in large-scale processors.',
                            'topics' => ['cryo', 'qubits']
                        ],
                        [
                            'title' => 'Surface Code Error Correction Under Correlated Phase Noise',
                            'abstract' => 'Proposing a dynamic decoder algorithm that compensates for spatially correlated environmental noise in silicon arrays.',
                            'topics' => ['error', 'noise']
                        ],
                        [
                            'title' => 'Optimizing Coherence Times in Silicon Spin Qubits',
                            'abstract' => 'Investigating isotopic purification techniques to minimize nuclear spin interference in silicon quantum dots.',
                            'topics' => ['coherence', 'dots']
                        ]
                    ]
                ],
                [
                    'title' => 'Annals of Cardiovascular Medicine',
                    'description' => 'Publishing clinical cardiology studies, transcatheter valve replacement telemetry, heart failure therapies, and vascular mechanics models.',
                    'about_text' => 'The Annals of Cardiovascular Medicine covers cardiovascular diagnostics, clinical trials, surgical innovations, and computer-simulated models of vascular fluid dynamics.',
                    'tags' => ['Cardiology', 'Vascular Mechanics', 'Clinical Trials', 'Heart Failure', 'Surgical Innovation', 'Diagnostics'],
                    'pages' => [
                        'Clinical Trials Protocols' => 'Trial registrations must be submitted (e.g. ClinicalTrials.gov) before submitting clinical study results.',
                        'Editorial Council' => 'Composed of practicing cardiologists, vascular surgeons, and physiological modelers.',
                        'Ethics Declarations' => 'Compliance reports for Helsinki Declaration standards and patient privacy protection measures.',
                        'Data Sharing Requirements' => 'Anonymized patient metrics and diagnostic imaging templates must be provided upon request.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Transcatheter Aortic Valve Replacement (TAVR) Longitudinal Outcome Analysis',
                            'abstract' => 'A 5-year follow-up study evaluating valve durability, calcification rates, and hemodynamics across 450 clinical patients.',
                            'topics' => ['valve', 'clinical']
                        ],
                        [
                            'title' => 'Hemodynamic Modeling of Coronary Artery Bifurcation Stents',
                            'abstract' => 'Using computational fluid dynamics to analyze shear stress patterns and predict restenosis risk in stent geometries.',
                            'topics' => ['hemodynamics', 'stents']
                        ],
                        [
                            'title' => 'Targeted Peptide Delivery Systems for Post-Infarct Myocardial Recovery',
                            'abstract' => 'Injectable hydrogels carrying localized peptides to stimulate cell proliferation and reduce scar tissue in animal models.',
                            'topics' => ['peptides', 'recovery']
                        ]
                    ]
                ],
                [
                    'title' => 'Harvard Review of Microeconomics',
                    'description' => 'Featuring micro-theory, pricing algorithms, game-theoretic auctions, behavioral decision models, and industrial organization telemetry.',
                    'about_text' => 'This review publishes high-impact papers in microeconomic theory and empirical industrial organization. We emphasize game theory applications, behavioral economics, and algorithmic pricing policies.',
                    'tags' => ['Microeconomic Theory', 'Game Theory', 'Pricing Algorithms', 'Behavioral Economics', 'Market Design', 'Auctions'],
                    'pages' => [
                        'Theoretical Guidelines' => 'Mathematical proofs must be fully detailed in supplemental appendices. Code for pricing simulations must be archived.',
                        'Editorial Council' => 'Distinguished microeconomists, game theorists, and behavioral scientists.',
                        'Review Methodology' => 'Two-stage blind review assessing mathematical correctness, behavioral model realism, and policy implications.',
                        'Data Preservation' => 'Requirements for archiving experimental databases and economic simulation scripts.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Dynamic Algorithmic Collusion in Digital Ad Auctions',
                            'abstract' => 'Analyzing how self-learning bidding bots establish cooperative pricing strategies without explicit communications.',
                            'topics' => ['collusion', 'auctions']
                        ],
                        [
                            'title' => 'Behavioral Biases in Multi-Stage Dynamic Subscription Models',
                            'abstract' => 'Evaluating consumer status-quo bias and auto-renewal friction points on customer lifetime valuation statistics.',
                            'topics' => ['behavioral', 'subscriptions']
                        ],
                        [
                            'title' => 'Auction Design for Fragmented Telecommunications Spectrum Licensing',
                            'abstract' => 'Proposing a multi-round combinatorial auction structure that prevents predatory bidding in regional markets.',
                            'topics' => ['auctions', 'spectrum']
                        ],
                        [
                            'title' => 'Interdependent Valuations in Decentralized Blockchain Resource Markets',
                            'abstract' => 'Modeling supply-demand equilibriums for validator fee markets during high-congestion transaction intervals.',
                            'topics' => ['blockchain', 'valuations']
                        ]
                    ]
                ],
                [
                    'title' => 'International Journal of Astrobiology',
                    'description' => 'Investigating planetary habitability telemetry, extremophile metabolic pathways, prebiotic chemistry, and cosmic biomarker detection.',
                    'about_text' => 'The International Journal of Astrobiology examines origin-of-life biology, prebiotic chemical synthesis, planetary atmosphere telemetry, and biological adaptations to extreme environments.',
                    'tags' => ['Planetary Habitability', 'Extremophiles', 'Prebiotic Chemistry', 'Biomarkers', 'Space Biology', 'Spectroscopy'],
                    'pages' => [
                        'Spectroscopy Data Specs' => 'Guidelines for archiving infrared, Raman, and mass spectroscopy profiles from meteorites or planetary simulations.',
                        'Astrobiology Council' => 'Geochemists, micro-biologists, and astrophysicists studying extrasolar biomarkers.',
                        'Peer Review Procedures' => 'Interdisciplinary review assessing geochemical models, biological limits, and planetary conditions.',
                        'Public Data Archiving' => 'Genomic sequences of extremophiles and chemical spectra must be deposited in open global databases.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Metabolic Pathways of Halophilic Extremophiles Under Martian Pressure Conditions',
                            'abstract' => 'Simulating survival rates and metabolic activity of salt-tolerant bacteria under carbon dioxide atmospheres at 6 millibars.',
                            'topics' => ['martian', 'extremophiles']
                        ],
                        [
                            'title' => 'Spectroscopic Detection of Biosignature Gases in Rocky Exoplanet Atmospheres',
                            'abstract' => 'Modeling atmospheric transmission spectra to identify overlapping chemical footprints of methane and oxygen.',
                            'topics' => ['biosignature', 'exoplanets']
                        ],
                        [
                            'title' => 'Prebiotic Synthesis of Amino Acids in Simulated Deep-Sea Hydrothermal Vents',
                            'abstract' => 'Recreating high-pressure thermal gradients to synthesize peptide chains from simple organic inputs without catalysts.',
                            'topics' => ['prebiotic', 'hydrothermal']
                        ]
                    ]
                ],
                [
                    'title' => 'Journal of Cognitive Neuroscience and AI',
                    'description' => 'Bridging human cortical mapping telemetry, artificial neural net alignment, cognitive mechanics, and sensory interface engineering.',
                    'about_text' => 'This journal explores the overlap between biological brain structures and computational artificial intelligence models. We emphasize cortical imaging, deep network interpretability, and brain-machine interfaces.',
                    'tags' => ['Cortical Mapping', 'Neural Alignment', 'Cognitive Modeling', 'Sensory Interfaces', 'Interpretability', 'Neuro-AI'],
                    'pages' => [
                        'Imaging Data Guidelines' => 'Required formats for fMRI brain mappings, EEG signal recordings, and artificial network weight vectors.',
                        'Editorial Council' => 'Neuroscientists and computer science researchers studying cognitive architectures.',
                        'Review Protocols' => 'Turnaround time is 8 weeks. Submissions are reviewed for biological plausibility and mathematical rigor.',
                        'Safety & Ethics Policies' => 'Guidelines for invasive neural implants, cognitive manipulation modeling, and neuro-data privacy.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Mapping Cortical Visual Responses to Artificial Convolutional Layer Activations',
                            'abstract' => 'Comparing human brain activity patterns during image recognition with state-of-the-art vision network activations.',
                            'topics' => ['visual', 'fMRI']
                        ],
                        [
                            'title' => 'Attention Mechanisms: Comparing Human Cognitive Focus with Transformer Weights',
                            'abstract' => 'Evaluating eyetracking metrics alongside attention matrix maps in generative models during reading tasks.',
                            'topics' => ['attention', 'transformer']
                        ],
                        [
                            'title' => 'Closed-Loop Neuromorphic Controllers for Prosthetic Tactile Feedback',
                            'abstract' => 'Translating robotic sensor pressure data into biological nerve stimulation pulses to restore touch sensation.',
                            'topics' => ['neuromorphic', 'prosthesis']
                        ]
                    ]
                ],
                [
                    'title' => 'Pakistan Journal of Renewable Energy',
                    'description' => 'Detailing solar photovoltaic efficiency, sub-regional wind telemetry, hydrokinetic turbine designs, and localized grid storage solutions.',
                    'about_text' => 'Focusing on renewable energy resources, regional grid integration, wind resource telemetry mapping, and energy conservation policies across agricultural and urban sectors.',
                    'tags' => ['Solar PV', 'Wind Telemetry', 'Hydrokinetics', 'Grid Storage', 'Energy Policy', 'Turbines'],
                    'pages' => [
                        'Meteorological Data Specs' => 'Guidelines for publishing solar irradiance, wind speed curves, and hydrokinetic flow measurements.',
                        'Editorial Committee' => 'Led by energy engineers, grid planners, and sustainable policy researchers.',
                        'Review Benchmarks' => 'Submissions must include structural test data or simulation parameters to verify engineering designs.',
                        'Access Options' => 'Open publication options to maximize regional engineering technology sharing.'
                    ],
                    'articles' => [
                        [
                            'title' => 'High-Efficiency Perovskite-Silicon Tandem Solar Cells in Arid Climates',
                            'abstract' => 'Testing cell degradation profiles and power conversion efficiencies under high ambient temperatures and dust conditions.',
                            'topics' => ['solar', 'perovskite']
                        ],
                        [
                            'title' => 'Wind Energy Resource Mapping and Telemetry in Coastal Sindh Regions',
                            'abstract' => 'Analyzing three years of wind speed telemetry to identify optimal coordinates for offshore turbine arrays.',
                            'topics' => ['wind', 'telemetry']
                        ],
                        [
                            'title' => 'Localized Battery Storage Dispatch Policies for Agricultural Tube Wells',
                            'abstract' => 'Developing control policies that optimize battery storage release to coordinate solar solar arrays with grid demand.',
                            'topics' => ['storage', 'grid']
                        ],
                        [
                            'title' => 'Hydrokinetic Flow Telemetry for Small-Scale Mountain Canal Turbines',
                            'abstract' => 'Designing low-head water turbines that generate electricity in rural canals without needing dams.',
                            'topics' => ['hydro', 'turbines']
                        ]
                    ]
                ],
                // 11-20 placeholder journals designed dynamically
                [
                    'title' => 'Review of Educational Telemetry',
                    'description' => 'Investigating online student engagement metrics, predictive learning analytics, and adaptive digital curriculum feedback systems.',
                    'about_text' => 'We publish scientific research on education technology, including real-time classroom telemetry, study patterns, and cognitive load modeling.',
                    'tags' => ['EdTech', 'Learning Analytics', 'Student Engagement', 'Adaptive Systems', 'Cognitive Load', 'Curriculum Design'],
                    'pages' => [
                        'Student Privacy Code' => 'Strict regulations concerning student data anonymity, parental consent forms, and database access.',
                        'Board of Advisors' => 'Education experts, learning psychologists, and software analytics developers.',
                        'Peer Review Guidelines' => 'Evaluation of data sample sizes, control groups, and statistical analytics validity.',
                        'Metadata Standards' => 'Formatting rules for publishing learning interaction logs and database variables.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Predicting Student Attrition Risks Using Logged LMS Activity Patterns',
                            'abstract' => 'Evaluating system event telemetry to flag students needing academic assistance, reducing drop-out rates by 18%.',
                            'topics' => ['LMS', 'attrition']
                        ],
                        [
                            'title' => 'Cognitive Load Optimization in Dynamic Science Simulations',
                            'abstract' => 'Using eye-tracking metrics to design simple user interfaces that prevent cognitive overload during complex tasks.',
                            'topics' => ['cognitive', 'simulations']
                        ],
                        [
                            'title' => 'Analyzing Peer Interaction Logs in Collaborative Coding Tasks',
                            'abstract' => 'Mapping git commits and slack activity to measure student contribution ratios in team software development.',
                            'topics' => ['collaboration', 'logs']
                        ]
                    ]
                ],
                [
                    'title' => 'Journal of Blockchain Technology and Finance',
                    'description' => 'Exploring consensus mechanics, decentralized lending protocols, smart contract audits, and central bank digital currency telemetry.',
                    'about_text' => 'A premier publication covering cryptography, market design, consensus algorithms, and financial regulations in decentralized ledgers.',
                    'tags' => ['Blockchain', 'Consensus Algorithms', 'Smart Contracts', 'DeFi', 'Cryptofinance', 'CBDC'],
                    'pages' => [
                        'Smart Contract Audits' => 'Requirement for publishing full solidity code, test coverage, and formal verification proofs.',
                        'Editorial Advisory' => 'Composed of crypto-economists, security engineers, and monetary policy experts.',
                        'Peer Review Guidelines' => 'Manuscripts are evaluated on protocol safety, gas efficiency, and economic attack resistance.',
                        'Open Code Archival' => 'All validation scripts and protocol simulation models must be uploaded to GitHub.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Liveness Guarantees in Proof-of-Stake Consensus Under Network Partitions',
                            'abstract' => 'Proposing a consensus protocol that retains network safety during wide-area internet connection failures.',
                            'topics' => ['consensus', 'liveness']
                        ],
                        [
                            'title' => 'Formal Verification of Flash-Loan Smart Contracts Against Reentrancy Attacks',
                            'abstract' => 'Using automated theorem proofing to validate decentralised protocols against transaction manipulation.',
                            'topics' => ['security', 'defi']
                        ],
                        [
                            'title' => 'Macroeconomic Implications of Multi-Tier Central Bank Digital Currencies',
                            'abstract' => 'Simulating liquidity movements during retail bank runs when high-quality digital reserves are available.',
                            'topics' => ['cbdc', 'banking']
                        ]
                    ]
                ],
                [
                    'title' => 'Global Journal of Organic Chemistry',
                    'description' => 'Analyzing biocatalytic synthesis paths, stereochemical structures, green chemical methods, and pharmaceutical reaction telemetry.',
                    'about_text' => 'This journal compiles research on synthetic methodologies, green chemistry, catalyst designs, and pharmaceutical reaction mechanics.',
                    'tags' => ['Biocatalysis', 'Stereochemistry', 'Green Chemistry', 'Synthesis', 'Reaction Kinetics', 'Pharmaceuticals'],
                    'pages' => [
                        'Synthesis Specifications' => 'Crystallographic database links, NMR spectra, and yield telemetry requirements.',
                        'Editorial Council' => 'Distinguished chemistry professors and pharmaceutical synthesis specialists.',
                        'Review Framework' => 'Peer specialists verify experimental reaction outputs and safety assessments.',
                        'Chemical Ethics' => 'Compliance with international regulations concerning chemical safety and dual-use compounds.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Enzymatic Synthesis of Chiral Intermediates in Aqueous Media',
                            'abstract' => 'Deploying engineered biocatalysts to synthesize pharmaceutical building blocks, achieving 98% enantiomeric excess.',
                            'topics' => ['enzymatic', 'chiral']
                        ],
                        [
                            'title' => 'Continuous Flow Reactors for Green Esterification Processes',
                            'abstract' => 'Utilizing solid acid catalysts in microfluidic channels to eliminate toxic solvent usage from industrial pipelines.',
                            'topics' => ['reactors', 'green']
                        ],
                        [
                            'title' => 'Reaction Kinetics of Transition Metal Catalyzed Cross-Couplings',
                            'abstract' => 'Using inline infrared spectroscopy to track catalyst activation states during carbon-carbon bond formations.',
                            'topics' => ['kinetics', 'catalysis']
                        ]
                    ]
                ],
                [
                    'title' => 'Scholarly Review of Distributed Systems',
                    'description' => 'Highlighting consensus protocols, distributed databases, geo-replication bottlenecks, and virtualization infrastructure.',
                    'about_text' => 'Covering computer networks, edge storage solutions, distributed transaction protocols, and cloud computing architectures.',
                    'tags' => ['Distributed Databases', 'Geo-replication', 'Virtualization', 'Cloud Computing', 'Consensus Protocols', 'Edge Storage'],
                    'pages' => [
                        'Infrastructure Specs' => 'Guidelines for reporting hardware setups, network speeds, and CPU allocation models.',
                        'Technical Board' => 'Software engineers and system architects specialized in high-performance computing.',
                        'Review Methodology' => 'Code must be submitted alongside performance benchmarking metrics under variable workloads.',
                        'System Security Code' => 'Security standards for encryption-at-rest, access keys, and secure socket connections.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Geo-Replicated Database Transaction Serialization Under Weak Consistency Models',
                            'abstract' => 'Proposing a clock synchronization protocol that ensures correct read-write ordering in global data systems.',
                            'topics' => ['database', 'consistency']
                        ],
                        [
                            'title' => 'Mitigating Tail Latency in Multi-Tenant Container Deployments',
                            'abstract' => 'A dynamic scheduling algorithm that dynamically adjusts CPU core assignments to prevent resource exhaustion.',
                            'topics' => ['latency', 'scheduler']
                        ],
                        [
                            'title' => 'Decentralized Edge Cache Protocols for Interactive Media Streaming',
                            'abstract' => 'Using prediction heuristics to pre-load content blocks to nearby cell tower servers, reducing delay by 24%.',
                            'topics' => ['cache', 'streaming']
                        ]
                    ]
                ],
                [
                    'title' => 'International Journal of Robotics and Automation',
                    'description' => 'Focusing on kinematic path planning, sensory feedback integration, human-robot interaction, and autonomous navigation telemetry.',
                    'about_text' => 'The journal details mechanical designs, computer vision algorithms, control systems, and field robotics applications.',
                    'tags' => ['Path Planning', 'Sensory Feedback', 'Human-Robot Interaction', 'Autonomous Navigation', 'Kinematics', 'Computer Vision'],
                    'pages' => [
                        'Robotic Testing Safety' => 'Mandatory documentation for obstacle avoidance, emergency shutdown switches, and field test limits.',
                        'Board of Robotics' => 'Mechanical designers, sensor specialists, and computer vision researchers.',
                        'Review Procedure' => 'Reviews assess kinematic modeling, simulation validations, and real-world robot test results.',
                        'Hardware Open Designs' => 'Guidelines for sharing CAD designs, motor specs, and micro-controller code.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Real-Time Path Planning in Dynamic Obstacle Environments Using Neural Fields',
                            'abstract' => 'Designing an online path optimizer that calculates collision-free paths for robotic arms in industrial environments.',
                            'topics' => ['path', 'neural']
                        ],
                        [
                            'title' => 'Impedance Control for Collaborative Human-Robot Assembly Systems',
                            'abstract' => 'Introducing force feedback controllers that ensure safe physical collaboration between assembly line robots and workers.',
                            'topics' => ['impedance', 'assembly']
                        ],
                        [
                            'title' => 'Visual Slam in Low-Visibility Conditions Using Thermal Camera Arrays',
                            'abstract' => 'Combining thermal images with visual inputs to allow drones to navigate through dust and smoke.',
                            'topics' => ['slam', 'thermal']
                        ]
                    ]
                ],
                [
                    'title' => 'Pakistan Journal of Water Resource Management',
                    'description' => 'Detailing aquifer recharge models, wastewater processing, regional canal telemetry, and flood protection structures.',
                    'about_text' => 'A national scientific record concerning water management, hydrological telemetry, crop irrigation modeling, and environmental conservation.',
                    'tags' => ['Aquifers', 'Canal Telemetry', 'Hydrology', 'Wastewater', 'Flood Protection', 'Irrigation'],
                    'pages' => [
                        'Hydrological Data Formats' => 'Formatting rules for publishing water level sensors, rainfall stats, and water quality parameters.',
                        'Editorial Council' => 'Hydrologists, civil engineers, and environmental policy developers.',
                        'Review Benchmarks' => 'Reviewers assess hydrological model accuracy, soil water sensors, and dataset size.',
                        'Water Safety Policy' => 'Regulations for publishing water quality data and chemical measurements.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Groundwater Table Depletion and Artificial Aquifer Recharge in Lahore',
                            'abstract' => 'Modeling aquifer recharge rates using municipal drainage networks to reverse falling water table trends.',
                            'topics' => ['aquifers', 'lahore']
                        ],
                        [
                            'title' => 'Irrigation Canal Flow Control Using Automated Sluice Gate Telemetry',
                            'abstract' => 'Deploying real-time canal level sensors to adjust gates, preventing water waste in downstream farming fields.',
                            'topics' => ['canal', 'sluice']
                        ],
                        [
                            'title' => 'Constructed Wetlands for Industrial Wastewater Reclamation in Karachi',
                            'abstract' => 'Evaluating bio-filtration performance of local reed species in removing heavy metal pollutants from textile wastewater.',
                            'topics' => ['wastewater', 'wetlands']
                        ]
                    ]
                ],
                [
                    'title' => 'Journal of Clinical Immunotherapy Research',
                    'description' => 'Publishing oncology immunotherapies, cellular receptors modeling, clinical trials telemetry, and immune response analytics.',
                    'about_text' => 'The journal details clinical progress in cancer immunotherapy, monoclonal antibodies, adaptive cell transfers, and patient diagnostics.',
                    'tags' => ['Immunotherapy', 'Oncology', 'Cellular Receptors', 'Clinical Trials', 'Monoclonal Antibodies', 'Diagnostics'],
                    'pages' => [
                        'Clinical Trials Protocol' => 'Pre-registration numbers must be provided for all clinical study submissions.',
                        'Medical Board' => 'Practicing immunologists, clinical oncologists, and cell therapy researchers.',
                        'Patient Data Ethics' => 'Anonymization guidelines, informed consent verifications, and ethics review board records.',
                        'Biomarker Data Formats' => 'Standards for submitting genetic sequencing and cell count data.'
                    ],
                    'articles' => [
                        [
                            'title' => 'CAR-T Cell Therapy Optimization Against Solid Tumor Receptors',
                            'abstract' => 'Engineering receptor affinity profiles to improve tumor targeting while minimizing off-target toxicity in clinical trials.',
                            'topics' => ['cart', 'tumors']
                        ],
                        [
                            'title' => 'PD-1 Blockade Resistance Mechanisms in Metastatic Melanoma Patients',
                            'abstract' => 'Tracking genetic changes in immune cells to understand why some tumors stop responding to antibody treatments.',
                            'topics' => ['resistance', 'blockade']
                        ],
                        [
                            'title' => 'Synergistic Combination of Oncolytic Viruses with Checkpoint Inhibitors',
                            'abstract' => 'Evaluating clinical outcomes of combined immunotherapy treatments in advanced lung cancer patients.',
                            'topics' => ['viruses', 'checkpoint']
                        ]
                    ]
                ],
                [
                    'title' => 'Annals of Materials Science & Nanotech',
                    'description' => 'Covering graphene synthesis, nanostructured alloys, piezoelectric converters, and thin-film semiconductors.',
                    'about_text' => 'Focused on materials physics, carbon nanotube synthesis, crystal growth telemetry, and solid-state battery material designs.',
                    'tags' => ['Graphene', 'Nanostructures', 'Piezoelectrics', 'Thin-films', 'Semiconductors', 'Materials Physics'],
                    'pages' => [
                        'Material Characterization' => 'Required formats for TEM images, XRD patterns, and stress-strain testing data.',
                        'Editorial Council' => 'Materials scientists, solid-state physicists, and nanotechnology researchers.',
                        'Review Standards' => 'All structural metrics must include error margin estimates and instrument calibration logs.',
                        'Material Safety' => 'Safety declarations regarding nanomaterial handling and environmental disposal.'
                    ],
                    'articles' => [
                        [
                            'title' => 'High-Yield Chemical Vapor Deposition of Single-Layer Graphene Sheets',
                            'abstract' => 'Optimizing reactor temperatures and gas flows to synthesize clean graphene sheets with minimal defects.',
                            'topics' => ['graphene', 'cvd']
                        ],
                        [
                            'title' => 'Flexible Piezoelectric Nanogenerators Based on Zinc Oxide Arrays',
                            'abstract' => 'Harvesting mechanical vibration energy using zinc oxide arrays, achieving 4.2 microwatts output per square centimeter.',
                            'topics' => ['piezoelectric', 'arrays']
                        ],
                        [
                            'title' => 'Thin-Film Perovskite Semiconductors for Near-Infrared Photodetectors',
                            'abstract' => 'Evaluating structural stability and electrical properties of perovskite crystals under continuous light exposure.',
                            'topics' => ['semiconductors', 'perovskite']
                        ]
                    ]
                ],
                [
                    'title' => 'Review of Cybersecurity and Information Assurance',
                    'description' => 'Exploring zero-trust security architecture, cryptanalysis, malware execution telemetry, and firmware protection schemas.',
                    'about_text' => 'A technical publication covering network defense, zero-day threat analysis, hardware security, and secure computing protocols.',
                    'tags' => ['Zero-trust', 'Cryptanalysis', 'Malware Telemetry', 'Firmware Security', 'Network Defense', 'Hardware Security'],
                    'pages' => [
                        'Vulnerability Disclosure' => 'Mandatory safety protocol for reporting zero-day threats, coordinate disclosure dates, and software patches.',
                        'Advisory Board' => 'Security researchers, cryptographic experts, and computer scientists.',
                        'Review Criteria' => 'Submissions must include proof-of-concept scripts or malware trace files in secure sandboxes.',
                        'Cryptographic Validation' => 'Algorithm specifications must conform to NIST verification benchmarks.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Malware Execution Detection in Virtualized Sandboxes Using API Trace Telemetry',
                            'abstract' => 'Analyzing real-time system call patterns with classifier models to flag hidden malware behaviors, achieving 98.6% accuracy.',
                            'topics' => ['malware', 'sandbox']
                        ],
                        [
                            'title' => 'Hardware-Enforced Cryptographic Key Separation in Secure Enclaves',
                            'abstract' => 'Designing secure micro-architectures that prevent memory leaking side-channel attacks during decryptions.',
                            'topics' => ['enclaves', 'keys']
                        ],
                        [
                            'title' => 'Zero-Trust Network Access Control Policies in Cloud Infrastructure',
                            'abstract' => 'Developing dynamic access control rules that adjust permissions based on user connection telemetry.',
                            'topics' => ['zerotrust', 'cloud']
                        ]
                    ]
                ],
                [
                    'title' => 'International Journal of Urban Infrastructure',
                    'description' => 'Analyzing intelligent traffic flow telemetry, urban sewer sensor networks, structural concrete monitoring, and smart grids.',
                    'about_text' => 'Dedicated to smart cities, structural monitoring, transit optimization, and sustainable urban resource distribution models.',
                    'tags' => ['Intelligent Traffic', 'Urban Sensors', 'Concrete Monitoring', 'Smart Grids', 'Smart Cities', 'Transit Optimization'],
                    'pages' => [
                        'Urban Data Formats' => 'Formatting rules for publishing municipal sensor grids, traffic patterns, and flow rates.',
                        'Editorial Council' => 'Civil engineers, urban planners, and computer science researchers.',
                        'Review Benchmarks' => 'Submissions must include field tests or verified simulation models of city environments.',
                        'Open Resource Policy' => 'Encouraging publication of open datasets to support public transit research.'
                    ],
                    'articles' => [
                        [
                            'title' => 'Dynamic Traffic Signal Optimization Using Real-Time Camera Telemetry Grid',
                            'abstract' => 'Using camera sensor grids to dynamically adjust signal durations, reducing city intersection delay by 31%.',
                            'topics' => ['traffic', 'sensors']
                        ],
                        [
                            'title' => 'Piezoelectric Sensor Arrays for Real-Time Bridge Concrete Monitoring',
                            'abstract' => 'Embedding sensor arrays in concrete structures to track micro-cracks and structural integrity over time.',
                            'topics' => ['concrete', 'bridge']
                        ],
                        [
                            'title' => 'Optimizing Urban Smart Grid Load Distribution Using Localized Batteries',
                            'abstract' => 'Developing a decentralized battery management system to balance energy demand across city sectors during heat waves.',
                            'topics' => ['smartgrid', 'battery']
                        ]
                    ]
                ]
            ];

            $allStatus = ['pending', 'approved', 'rejected'];
            $rejectionReasons = [
                'The submission lacks empirical validation and control group comparisons for the proposed methodology.',
                'The theoretical model violates basic physical thermodynamic laws and lacks raw sensor telemetry outputs.',
                'Methodology sections are insufficient to allow independent peer replication of the experiment.',
                'Reviewers identified significant overlaps with prior publications without appropriate citations.',
                'The statistical analysis lacks statistical significance proof, and sample sizes are too small.'
            ];

            $imgKeys = array_keys($unsplashImages);

            // High-quality Unsplash academic/scientific stock cover images for the 20 magazines
            $magazineCovers = [
                'https://images.unsplash.com/photo-1507413245164-6160d8298b31?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1532094349884-543bc11b234d?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1532187643603-ba119ca4109e?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1507668077129-56e32842fceb?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1532187643603-ba119ca4109e?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1579154204601-01588f351e67?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1614064641938-3bbee52942c7?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1582719508461-905c673771fd?auto=format&fit=crop&w=800&q=80',
            ];

            // Loop and seed Magazines
            foreach ($magazinesData as $idx => $m) {
                // Generate a premium magazine cover using the unsplash list
                $coverPath = $magazineCovers[$idx % count($magazineCovers)];

                $magazine = Magazine::create([
                    'title' => $m['title'],
                    'slug' => Str::slug($m['title']),
                    'cover_image' => $coverPath,
                    'description' => $m['description'],
                    'about_text' => $m['about_text']
                ]);

                // Seed 4 to 5 domain-tailored pages
                $pageIdx = 0;
                foreach ($m['pages'] as $pTitle => $pContent) {
                    MagazinePage::create([
                        'magazine_id' => $magazine->id,
                        'title' => $pTitle,
                        'slug' => Str::slug($pTitle),
                        'content' => "<h3>" . e($pTitle) . "</h3><p>" . e($pContent) . "</p><p>All submissions must follow the designated formatting instructions to ensure rapid editorial sorting and review compliance.</p>",
                        'sort_order' => $pageIdx++
                    ]);
                }

                // Seed 6 to 7 domain-specific tags for this magazine
                $tagPoolIds = [];
                foreach ($m['tags'] as $tagName) {
                    $tag = Tag::create([
                        'magazine_id' => $magazine->id,
                        'name' => $tagName
                    ]);
                    $tagPoolIds[] = $tag->id;
                }

                // Seed 3 to 4 articles for this magazine
                $articleCount = rand(3, 4);
                for ($a = 0; $a < $articleCount; $a++) {
                    // Pick a template article spec if available, otherwise construct dummy
                    $artTitle = "";
                    $artAbstract = "";
                    if (isset($m['articles'][$a])) {
                        $artTitle = $m['articles'][$a]['title'];
                        $artAbstract = $m['articles'][$a]['abstract'];
                    } else {
                        $artTitle = "Empirical Telemetry Investigation of " . $m['tags'][rand(0, count($m['tags'])-1)] . " Applications";
                        $artAbstract = "This paper details secondary experimental investigations and validation outcomes concerning advanced system architectures. Our empirical data shows significant enhancement margins.";
                    }

                    $status = $allStatus[array_rand($allStatus)];
                    $rejectionReason = ($status === 'rejected') ? $rejectionReasons[array_rand($rejectionReasons)] : null;

                    // Select 2 to 3 unique random images for this article
                    $selectedImages = [];
                    $tempImgKeys = $imgKeys;
                    shuffle($tempImgKeys);
                    $imgCount = rand(2, 3);
                    for ($i = 0; $i < $imgCount; $i++) {
                        $selectedImages[] = [
                            'url' => $unsplashImages[$tempImgKeys[$i]],
                            'key' => $tempImgKeys[$i]
                        ];
                    }

                    // Build Rich Text HTML with figures
                    $fullTextHtml = "<h1>1. Introduction to Research Study</h1>";
                    $fullTextHtml .= "<p>The integration of advanced systems requires careful calibration of sensor arrays, software telemetry channels, and structural components. Traditional approaches often fail to account for high-load environmental conditions, leading to micro-latency delays and potential system failures.</p>";
                    
                    // Embed Image 1
                    $fullTextHtml .= "<h2>2. Methodology and Sensor Array Configurations</h2>";
                    $fullTextHtml .= "<p>We set up a multi-point monitoring system to capture performance data under varying workloads. The initial layout of the physical research equipment and data interfaces is pictured in the figure below:</p>";
                    
                    $fullTextHtml .= '<div class="my-8 flex flex-col items-center">';
                    $fullTextHtml .= '<img src="' . $selectedImages[0]['url'] . '" alt="Figure 1: Research setup" class="rounded-2xl border border-zinc-200/80 dark:border-zinc-800/80 max-w-full h-auto shadow-sm" />';
                    $fullTextHtml .= '<span class="text-xs text-zinc-500 dark:text-zinc-400 mt-3 font-mono text-center">Figure 1.1: ' . ucwords(str_replace('_', ' ', $selectedImages[0]['key'])) . ' and initial calibration setup.</span>';
                    $fullTextHtml .= '</div>';

                    $fullTextHtml .= "<p>Subsequent iterations modified the electrical currents and data routing tables to measure thermal outputs and packet loss margins. Control variables remained strictly localized to minimize external noise.</p>";

                    // Embed Image 2
                    $fullTextHtml .= "<h2>3. Statistical Analysis and Performance Metrics</h2>";
                    $fullTextHtml .= "<p>After conducting 48 simulation loops, we compiled the raw data logs and constructed comparative charts to track optimization pathways. The performance data points are illustrated here:</p>";

                    $fullTextHtml .= '<div class="my-8 flex flex-col items-center">';
                    $fullTextHtml .= '<img src="' . $selectedImages[1]['url'] . '" alt="Figure 2: Performance metrics" class="rounded-2xl border border-zinc-200/80 dark:border-zinc-800/80 max-w-full h-auto shadow-sm" />';
                    $fullTextHtml .= '<span class="text-xs text-zinc-500 dark:text-zinc-400 mt-3 font-mono text-center">Figure 1.2: Captured ' . ucwords(str_replace('_', ' ', $selectedImages[1]['key'])) . ' output analytics.</span>';
                    $fullTextHtml .= '</div>';

                    $fullTextHtml .= "<p>The data confirms that dynamically managing resources reduces bottleneck occurrences by up to 28% without degrading processing safety margins.</p>";

                    // Optional Image 3
                    if (isset($selectedImages[2])) {
                        $fullTextHtml .= "<h2>4. Diagnostic Diagnostics and Secondary Loops</h2>";
                        $fullTextHtml .= "<p>We also analyzed secondary parameters during stress testing cycles to verify structural limits. The extra feedback curves are captured below:</p>";

                        $fullTextHtml .= '<div class="my-8 flex flex-col items-center">';
                        $fullTextHtml .= '<img src="' . $selectedImages[2]['url'] . '" alt="Figure 3: Secondary parameters" class="rounded-2xl border border-zinc-200/80 dark:border-zinc-800/80 max-w-full h-auto shadow-sm" />';
                        $fullTextHtml .= '<span class="text-xs text-zinc-500 dark:text-zinc-400 mt-3 font-mono text-center">Figure 1.3: Secondary telemetry mapping of ' . ucwords(str_replace('_', ' ', $selectedImages[2]['key'])) . ' data.</span>';
                        $fullTextHtml .= '</div>';
                    }

                    $fullTextHtml .= "<h2>5. Conclusion and Future Research Pathways</h2>";
                    $fullTextHtml .= "<p>In conclusion, the proposed methodology successfully optimizes operational parameters under adverse test scenarios. Future research should focus on scaling the control architectures to manage larger, multi-site deployments.</p>";

                    $article = Article::create([
                        'magazine_id' => $magazine->id,
                        'user_id' => $authorUser->id,
                        'title' => $artTitle,
                        'slug' => Str::slug($artTitle) . '-' . Str::random(5),
                        'abstract' => "<p>" . e($artAbstract) . "</p>",
                        'full_text' => $fullTextHtml,
                        'pdf_path' => null,
                        'status' => $status,
                        'rejection_reason' => $rejectionReason,
                        'clicks' => rand(10, 100),
                        'impressions' => rand(150, 1000)
                    ]);

                    // Attach 4 to 5 random tags from the magazine's tag pool
                    $shuffledTagIds = $tagPoolIds;
                    shuffle($shuffledTagIds);
                    $tagCount = rand(4, 5);
                    $selectedTagIds = array_slice($shuffledTagIds, 0, $tagCount);

                    $article->tags()->sync($selectedTagIds);
                }
            }

            DB::commit();
            $this->command->info("MagazineDevDataSeeder completed successfully. Seeded 20 elite magazines!");
        } catch (\Exception $e) {
            DB::rollBack();
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Exception $ex) {
                // Ignore nested database exception
            }
            throw $e;
        }
    }
}
