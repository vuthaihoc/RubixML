<?php

namespace Rubix\ML\Graph;

use Rubix\ML\Estimator;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Graph\Nodes\Leaf;
use Rubix\ML\Graph\Nodes\Comparison;
use Rubix\ML\Graph\Nodes\BinaryNode;
use InvalidArgumentException;

/**
 * CART
 *
 * Classification and Regression Tree or *CART* is a binary tree that uses
 * comparision (*decision*) nodes at every split in the training data to
 * locate a leaf node.
 *
 * [1] W. Y. Loh. (2011). Classification and Regression Trees.
 *
 * @category    Machine Learning
 * @package     Rubix/ML
 * @author      Andrew DalPino
 */
abstract class CART implements Tree
{
    const BETA = 1e-8;

    /**
     * The root node of the tree.
     *
     * @var \Rubix\ML\Graph\Nodes\Comparison|null
     */
    protected $root;

    /**
     * The maximum depth of a branch before it is forced to terminate.
     *
     * @var int
     */
    protected $maxDepth;

    /**
     * The maximum number of samples that a leaf node can contain.
     *
     * @var int
     */
    protected $maxLeafSize;

    /**
     * The minimum increase in purity necessary for a node not to be post pruned.
     * 
     * @var float
     */
    protected $minPurityIncrease;

    /**
     * @param  int  $maxDepth
     * @param  int  $maxLeafSize
     * @param  float  $minPurityIncrease
     * @throws \InvalidArgumentException
     * @return void
     */
    public function __construct(int $maxDepth = PHP_INT_MAX, int $maxLeafSize = 3, float $minPurityIncrease = 0.)
    {
        if ($maxDepth < 1) {
            throw new InvalidArgumentException('A tree cannot have depth less'
                . ' than 1.');
        }

        if ($maxLeafSize < 1) {
            throw new InvalidArgumentException('At least one sample is required'
                . ' to create a leaf.');
        }

        if ($minPurityIncrease < 0.) {
            throw new InvalidArgumentException('Min purity increase must be'
                . " greater than or equal to 0, $minPurityIncrease given.");
        }

        $this->maxDepth = $maxDepth;
        $this->maxLeafSize = $maxLeafSize;
        $this->minPurityIncrease = $minPurityIncrease;
    }

    /**
     * Greedy algorithm to choose the best split for a given dataset.
     *
     * @param  \Rubix\ML\Datasets\Labeled  $dataset
     * @param  int  $depth
     * @return \Rubix\ML\Graph\Nodes\Comparison
     */
    abstract protected function findBestSplit(Labeled $dataset, int $depth) : Comparison;

    /**
     * Terminate the branch by selecting the most likely outcome as the
     * prediction.
     *
     * @param  \Rubix\ML\Datasets\Labeled  $dataset
     * @param  int  $depth
     * @return \Rubix\ML\Graph\Nodes\BinaryNode
     */
    abstract protected function terminate(Labeled $dataset, int $depth) : BinaryNode;

    /**
     * Return the root node of the tree.
     * 
     * @return \Rubix\ML\Graph\Nodes\Comparison|null
     */
    public function root() : ?Comparison
    {
        return $this->root;
    }

    /**
     * Insert a root node into the tree and recursively split the training data
     * until a terminating condition is met.
     *
     * @param  \Rubix\ML\Datasets\Labeled  $dataset
     * @return void
     */
    public function grow(Labeled $dataset) : void
    {
        $depth = 1;

        $this->root = $this->findBestSplit($dataset, $depth);

        $stack = [[$this->root, $depth]];

        while($stack) {
            list($current, $depth) = array_pop($stack) ?? [];

            list($left, $right) = $current->groups();

            $depth++;

            if ($left->empty() or $right->empty()) {
                $node = $this->terminate($left->merge($right), $depth);
    
                $current->attachLeft($node);
                $current->attachRight($node);

                continue 1;
            }
    
            if ($depth >= $this->maxDepth) {
                $current->attachLeft($this->terminate($left, $depth));
                $current->attachRight($this->terminate($right, $depth));
                
                continue 1;
            }

            if ($left->numRows() > $this->maxLeafSize) {
                $node = $this->findBestSplit($left, $depth);

                if ($node->purityIncrease() + self::BETA > $this->minPurityIncrease) {
                    $current->attachLeft($node);

                    $stack[] = [$node, $depth];
                } else {
                    $current->attachLeft($this->terminate($left, $depth));
                }
            } else {
                $current->attachLeft($this->terminate($left, $depth));
            }
    
            if ($right->numRows() > $this->maxLeafSize) {
                $node = $this->findBestSplit($right, $depth);
    
                if ($node->purityIncrease() + self::BETA > $this->minPurityIncrease) {
                    $current->attachRight($node);

                    $stack[] = [$node, $depth];
                } else {
                    $current->attachRight($this->terminate($right, $depth));
                }
            } else {
                $current->attachRight($this->terminate($right, $depth));
            }

            $current->cleanup();
        }
    }

    /**
     * Search the tree for a leaf node.
     *
     * @param  array  $sample
     * @return \Rubix\ML\Graph\Nodes\BinaryNode|null
     */
    public function search(array $sample) : ?BinaryNode
    {
        $current = $this->root;

        while ($current) {
            if ($current instanceof Comparison) {
                $value = $current->value();

                if (is_string($value)) {
                    if ($sample[$current->column()] === $value) {
                        $current = $current->left();
                    } else {
                        $current = $current->right();
                    }
                } else {
                    if ($sample[$current->column()] < $value) {
                        $current = $current->left();
                    } else {
                        $current = $current->right();
                    }
                }

                continue 1;
            }

            if ($current instanceof Leaf) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Return an array indexed by feature column that contains the normalized
     * importance score of that column in determining the overall prediction.
     *
     * @return array
     */
    public function featureImportances() : array
    {
        if (is_null($this->root)) {
            return [];
        }

        $nodes = $this->dump($this->root);

        $importances = [];

        foreach ($nodes as $node) {
            if ($node instanceof Comparison) {
                $index = $node->column();

                if (isset($importances[$index])) {
                    $importances[$index] += $node->purityIncrease();
                } else {
                    $importances[$index] = $node->purityIncrease();
                }
            }
        }

        $total = array_sum($importances) ?: self::BETA;

        foreach ($importances as &$importance) {
            $importance /= $total;
        }

        arsort($importances);

        return $importances;
    }

    /**
     * Return an array of all the nodes in the tree starting at a
     * given node.
     *
     * @param  \Rubix\ML\Graph\Nodes\BinaryNode  $current
     * @return array
     */
    public function dump(BinaryNode $current) : array
    {
        if ($current instanceof Leaf) {
            return [$current];
        }

        $left = $right = [];

        $node = $current->left();

        if ($node instanceof BinaryNode) {
            $left = $this->dump($node);
        }

        $node = $current->right();

        if ($node instanceof BinaryNode) {
            $right = $this->dump($node);
        }

        return array_merge([$current], $left, $right);
    }

    /**
     * Is the tree bare?
     *
     * @return bool
     */
    public function bare() : bool
    {
        return is_null($this->root);
    }
}
